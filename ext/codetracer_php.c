#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_ini.h"
#include "ext/standard/info.h"
#include "SAPI.h"
#include "php_codetracer.h"
#include "codetracer_trace_writer.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <unistd.h>
#include <sys/stat.h>
#include <sys/types.h>

/* Global state */
static void (*original_zend_execute_ex)(zend_execute_data *execute_data);
static size_t (*original_sapi_ub_write)(const char *str, size_t str_length) = NULL;
static TraceWriterHandle *trace_writer = NULL;
static int tracing_enabled = 0;
static int in_trace_hook = 0;  /* Prevent recursive tracing */
static int in_io_hook = 0;     /* Prevent recursive IO capture */
static char *output_dir = NULL;

/* Maximum length for serialized string values */
#define MAX_VALUE_REPR_LEN 1024

/* Serialize a zval into a trace variable record */
static void serialize_zval(TraceWriterHandle *writer, const char *name, zval *val)
{
    if (!val || Z_TYPE_P(val) == IS_UNDEF) return;

    switch (Z_TYPE_P(val)) {
    case IS_NULL:
        trace_writer_register_variable_raw(writer, name, "null", TK_RAW, "null");
        break;
    case IS_FALSE:
        trace_writer_register_variable_raw(writer, name, "false", TK_BOOL, "boolean");
        break;
    case IS_TRUE:
        trace_writer_register_variable_raw(writer, name, "true", TK_BOOL, "boolean");
        break;
    case IS_LONG:
        trace_writer_register_variable_int(writer, name, Z_LVAL_P(val), TK_INT, "integer");
        break;
    case IS_DOUBLE: {
        char buf[64];
        snprintf(buf, sizeof(buf), "%g", Z_DVAL_P(val));
        trace_writer_register_variable_raw(writer, name, buf, TK_FLOAT, "float");
        break;
    }
    case IS_STRING: {
        const char *str = Z_STRVAL_P(val);
        size_t len = Z_STRLEN_P(val);
        if (len <= MAX_VALUE_REPR_LEN) {
            trace_writer_register_variable_raw(writer, name, str, TK_STRING, "string");
        } else {
            /* Truncate long strings */
            char buf[MAX_VALUE_REPR_LEN + 4];
            memcpy(buf, str, MAX_VALUE_REPR_LEN);
            buf[MAX_VALUE_REPR_LEN] = '.';
            buf[MAX_VALUE_REPR_LEN + 1] = '.';
            buf[MAX_VALUE_REPR_LEN + 2] = '.';
            buf[MAX_VALUE_REPR_LEN + 3] = '\0';
            trace_writer_register_variable_raw(writer, name, buf, TK_STRING, "string");
        }
        break;
    }
    case IS_ARRAY: {
        char buf[64];
        snprintf(buf, sizeof(buf), "Array(%d)", zend_hash_num_elements(Z_ARRVAL_P(val)));
        trace_writer_register_variable_raw(writer, name, buf, TK_SEQ, "array");
        break;
    }
    case IS_OBJECT: {
        zend_class_entry *ce = Z_OBJCE_P(val);
        char buf[256];
        snprintf(buf, sizeof(buf), "%s {}", ZSTR_VAL(ce->name));
        trace_writer_register_variable_raw(writer, name, buf, TK_STRUCT, ZSTR_VAL(ce->name));
        break;
    }
    default: {
        char buf[32];
        snprintf(buf, sizeof(buf), "<type:%d>", Z_TYPE_P(val));
        trace_writer_register_variable_raw(writer, name, buf, TK_RAW, "unknown");
        break;
    }
    }
}

/* Serialize a zval as a return value */
static void serialize_return_zval(TraceWriterHandle *writer, zval *val)
{
    if (!val || Z_TYPE_P(val) == IS_UNDEF) {
        trace_writer_register_return(writer);
        return;
    }

    switch (Z_TYPE_P(val)) {
    case IS_NULL:
        trace_writer_register_return_raw(writer, "null", TK_RAW, "null");
        break;
    case IS_FALSE:
        trace_writer_register_return_raw(writer, "false", TK_BOOL, "boolean");
        break;
    case IS_TRUE:
        trace_writer_register_return_raw(writer, "true", TK_BOOL, "boolean");
        break;
    case IS_LONG:
        trace_writer_register_return_int(writer, Z_LVAL_P(val), TK_INT, "integer");
        break;
    case IS_DOUBLE: {
        char buf[64];
        snprintf(buf, sizeof(buf), "%g", Z_DVAL_P(val));
        trace_writer_register_return_raw(writer, buf, TK_FLOAT, "float");
        break;
    }
    case IS_STRING: {
        const char *str = Z_STRVAL_P(val);
        size_t len = Z_STRLEN_P(val);
        if (len <= MAX_VALUE_REPR_LEN) {
            trace_writer_register_return_raw(writer, str, TK_STRING, "string");
        } else {
            char buf[MAX_VALUE_REPR_LEN + 4];
            memcpy(buf, str, MAX_VALUE_REPR_LEN);
            buf[MAX_VALUE_REPR_LEN] = '.';
            buf[MAX_VALUE_REPR_LEN + 1] = '.';
            buf[MAX_VALUE_REPR_LEN + 2] = '.';
            buf[MAX_VALUE_REPR_LEN + 3] = '\0';
            trace_writer_register_return_raw(writer, buf, TK_STRING, "string");
        }
        break;
    }
    case IS_ARRAY: {
        char buf[64];
        snprintf(buf, sizeof(buf), "Array(%d)", zend_hash_num_elements(Z_ARRVAL_P(val)));
        trace_writer_register_return_raw(writer, buf, TK_SEQ, "array");
        break;
    }
    case IS_OBJECT: {
        zend_class_entry *ce = Z_OBJCE_P(val);
        char buf[256];
        snprintf(buf, sizeof(buf), "%s {}", ZSTR_VAL(ce->name));
        trace_writer_register_return_raw(writer, buf, TK_STRUCT, ZSTR_VAL(ce->name));
        break;
    }
    default: {
        char buf[32];
        snprintf(buf, sizeof(buf), "<type:%d>", Z_TYPE_P(val));
        trace_writer_register_return_raw(writer, buf, TK_RAW, "unknown");
        break;
    }
    }
}

/*
 * Our SAPI unbuffered-write hook.
 *
 * PHP routes every stdout-bound write — `echo`, `print`, `var_dump`,
 * `printf`, raw HTML body output — through `sapi_module.ub_write`.
 * By interposing here, we capture all stdout chunks as Write events
 * via `register_special_event(ELK_WRITE, ...)` without having to
 * enumerate individual print-style functions.  This mirrors the
 * canonical IO-capture pattern established for the Python recorder
 * in handoff entry 1.27 (`register_special_event` with an
 * EventLogKind matching the destination FD).
 *
 * Note: stderr writes (PHP `error_log`, `fwrite(STDERR, ...)`) and
 * direct `fwrite` to file descriptors do NOT go through ub_write;
 * dedicated capture hooks for those are tracked as a follow-up in
 * AUDIT-CTFS-2026-05.md.
 *
 * The `in_io_hook` re-entry guard prevents accidental recursion if
 * the trace writer (or downstream code) ever calls back into a
 * print path.
 */
static size_t codetracer_sapi_ub_write(const char *str, size_t str_length)
{
    if (tracing_enabled && !in_io_hook && trace_writer && str && str_length > 0) {
        in_io_hook = 1;
        /* Copy to a NUL-terminated buffer because the FFI takes a
         * C string.  `register_special_event` treats `content` as
         * opaque text, so this preserves the bytes verbatim. */
        char *buf = (char *)emalloc(str_length + 1);
        if (buf) {
            memcpy(buf, str, str_length);
            buf[str_length] = '\0';
            trace_writer_register_special_event(
                trace_writer, ELK_WRITE, "stdout", buf);
            efree(buf);
        }
        in_io_hook = 0;
    }
    if (original_sapi_ub_write) {
        return original_sapi_ub_write(str, str_length);
    }
    return str_length;
}

/* Our execute_ex hook */
static void codetracer_execute_ex(zend_execute_data *execute_data)
{
    if (!tracing_enabled || in_trace_hook || !trace_writer) {
        original_zend_execute_ex(execute_data);
        return;
    }

    in_trace_hook = 1;

    /* Extract function info */
    zend_function *func = execute_data->func;
    const char *func_name = NULL;
    const char *file_name = NULL;
    uint32_t line_no = 0;
    int have_info = 0;

    if (func) {
        if (func->common.function_name) {
            func_name = ZSTR_VAL(func->common.function_name);
        }
        if (func->type == ZEND_USER_FUNCTION) {
            file_name = ZSTR_VAL(func->op_array.filename);
            line_no = func->op_array.line_start;
        }
    }

    /* Register the call and capture arguments.
     *
     * IMPORTANT: per the canonical FFI contract (see
     * trace_writer_register_call doc-comment in
     * codetracer_trace_writer.h), the args are NOT passed as a
     * parameter to register_call — they must be staged via
     * register_variable_* (or, on Nim-backed writers,
     * register_call_arg) BEFORE register_call so the call record
     * decoder sees them at function-entry time.  This mirrors the
     * canonical pattern fixed in the Ruby recorder (handoff entry
     * 1.22 in /tmp/isonim-migration.txt) and the JS recorder
     * (handoff entry 1.38).
     *
     * The current C FFI (codetracer_trace_writer_ffi) does not
     * expose register_call_arg, so the args emitted here surface as
     * scoped variables at the function-entry step rather than on
     * CallRecord.args directly.  Populating CallRecord.args end-to-
     * end requires extending the C FFI (see AUDIT-CTFS-2026-05.md).
     */
    if (func_name && file_name) {
        uintptr_t fid = trace_writer_ensure_function_id(
            trace_writer, func_name, file_name, (int64_t)line_no);

        /* Stage call arguments BEFORE register_call so they appear
         * in the function-entry frame (canonical CTFS recorder
         * pattern). */
        if (func->type == ZEND_USER_FUNCTION) {
            uint32_t num_args = ZEND_CALL_NUM_ARGS(execute_data);
            zend_op_array *op_array = &func->op_array;

            for (uint32_t i = 0; i < num_args && i < op_array->num_args; i++) {
                zval *arg = ZEND_CALL_ARG(execute_data, i + 1);
                const char *param_name = ZSTR_VAL(op_array->vars[i]);
                serialize_zval(trace_writer, param_name, arg);
            }
        }

        trace_writer_register_call(trace_writer, fid);
        trace_writer_register_step(trace_writer, file_name, (int64_t)line_no);
        have_info = 1;
    }

    in_trace_hook = 0;

    /* Call original handler (executes the actual PHP code) */
    original_zend_execute_ex(execute_data);

    /* After return — capture return value and register return */
    if (tracing_enabled && !in_trace_hook && trace_writer && have_info) {
        in_trace_hook = 1;

        if (execute_data->return_value) {
            serialize_return_zval(trace_writer, execute_data->return_value);
        } else {
            trace_writer_register_return(trace_writer);
        }

        in_trace_hook = 0;
    }
}

/* Module initialization */
PHP_MINIT_FUNCTION(codetracer)
{
    /* Save and replace zend_execute_ex */
    original_zend_execute_ex = zend_execute_ex;
    zend_execute_ex = codetracer_execute_ex;
    return SUCCESS;
}

/* Module shutdown */
PHP_MSHUTDOWN_FUNCTION(codetracer)
{
    /* Restore original handler */
    zend_execute_ex = original_zend_execute_ex;
    return SUCCESS;
}

/* Request initialization — create trace writer */
PHP_RINIT_FUNCTION(codetracer)
{
    const char *enabled_env = getenv("CODETRACER_ENABLED");
    if (!enabled_env || strcmp(enabled_env, "1") != 0) {
        tracing_enabled = 0;
        return SUCCESS;
    }

    /*
     * Determine the trace output directory.
     *
     * CODETRACER_TRACE_DIR takes priority -- it is set by web_bootstrap.php
     * to a per-request directory so that the C extension writes its trace
     * files (trace.bin, trace_metadata.json, trace_paths.json) into the
     * same directory that the span manifest references via its "trace_dir"
     * field.
     *
     * If CODETRACER_TRACE_DIR is not set (e.g. CLI or standalone mode),
     * fall back to creating a timestamped subdirectory under
     * CODETRACER_OUTPUT_DIR (or /tmp/codetracer_traces).
     */
    char trace_dir[4096];
    const char *explicit_trace_dir = getenv("CODETRACER_TRACE_DIR");
    if (explicit_trace_dir && explicit_trace_dir[0] != '\0') {
        snprintf(trace_dir, sizeof(trace_dir), "%s", explicit_trace_dir);
        /* The bootstrap already created this directory */
        mkdir(trace_dir, 0755);
    } else {
        const char *out = getenv("CODETRACER_OUTPUT_DIR");
        if (!out) out = "/tmp/codetracer_traces";
        snprintf(trace_dir, sizeof(trace_dir), "%s/%ld_%d",
                out, (long)time(NULL), (int)getpid());
        mkdir(out, 0755);
        mkdir(trace_dir, 0755);
    }

    /* Create trace writer */
    const char *script = SG(request_info).path_translated;
    if (!script) script = "php";

    /* CTFS V4 multi-stream is the canonical CodeTracer trace format
     * — see `metacraft-specs/policies/recorder-test-requirements.md`
     * §1 and `Recorder-CLI-Conventions.md` §4.  We pass `FMT_CTFS`
     * (alias for the Nim FFI's `FFI_TRACE_FORMAT_BINARY`, value 2):
     * the Nim writer interprets that as multi-stream V4 (steps.dat /
     * calls.dat / values.dat / paths.dat / meta.dat / etc.), which is
     * the layout `ct print --full` decodes directly.
     *
     * Pre-2026-05 the PHP recorder linked against the Rust FFI from
     * `codetracer-trace-format`, where format=2 meant the legacy
     * CBOR+Zstd writer that ct-print couldn't decode at all.
     * `ext/build.sh` now links against the Nim FFI from
     * `codetracer-trace-format-nim`, where the same value activates
     * the multi-stream path. */
    trace_writer = trace_writer_new(script, FMT_CTFS);
    if (!trace_writer) {
        return SUCCESS;
    }

    /* Begin writing the events stream.  The Nim multi-stream writer
     * derives the `.ct` container path from the events path; metadata
     * and paths streams are written into the container on close, so the
     * legacy `trace_metadata.json` / `trace_paths.json` sidecars are no
     * longer produced. */
    char path_buf[4096];
    snprintf(path_buf, sizeof(path_buf), "%s/trace.bin", trace_dir);
    trace_writer_begin_events(trace_writer, path_buf);

    /* Set working directory */
    char cwd[4096];
    if (getcwd(cwd, sizeof(cwd))) {
        trace_writer_set_workdir(trace_writer, cwd);
    }

    /* Register None type first — the trace writer requires type ID 0 to be None */
    trace_writer_ensure_type_id(trace_writer, TK_NONE, "None");

    /* Pre-register PHP types */
    trace_writer_ensure_type_id(trace_writer, TK_INT, "integer");
    trace_writer_ensure_type_id(trace_writer, TK_FLOAT, "float");
    trace_writer_ensure_type_id(trace_writer, TK_STRING, "string");
    trace_writer_ensure_type_id(trace_writer, TK_BOOL, "boolean");
    trace_writer_ensure_type_id(trace_writer, TK_SEQ, "array");
    trace_writer_ensure_type_id(trace_writer, TK_RAW, "object");
    trace_writer_ensure_type_id(trace_writer, TK_STRUCT, "class");

    /* Register start — must be called after TK_NONE is registered */
    if (script) {
        trace_writer_start(trace_writer, script, 1);

        /* Register a synthetic <toplevel> Function + Call so the script's
         * top-level execution surfaces as a regular call frame.  Without
         * this, ct-print only shows internal function calls and hides the
         * outermost frame — every per-program test that asserts on call
         * counts ends up off-by-one (see e2e flow_test: expects 2 funcs/
         * calls/returns for {<toplevel>, compute}, was getting 1).
         *
         * The corresponding return-value Value record is emitted in
         * RSHUTDOWN before trace_writer_close so call_exit carries the
         * synthesised None payload. */
        uintptr_t toplevel_fid = trace_writer_ensure_function_id(
            trace_writer, "<toplevel>", script, 1);
        trace_writer_register_call(trace_writer, toplevel_fid);
    }

    tracing_enabled = 1;
    output_dir = strdup(trace_dir);

    /* Install the SAPI unbuffered-write hook for stdout capture.
     * We do this AFTER tracing_enabled = 1 so the very first event
     * (e.g. an early `echo`) is captured.  The hook is restored in
     * RSHUTDOWN so the next request starts from a clean slate.  The
     * SAPI handler is per-process global state on most SAPIs (cli,
     * cli-server, fpm, apache); restoring it makes us safe under
     * non-tracing requests and under graceful module unload. */
    if (!original_sapi_ub_write) {
        original_sapi_ub_write = sapi_module.ub_write;
        sapi_module.ub_write = codetracer_sapi_ub_write;
    }

    return SUCCESS;
}

/* Request shutdown — finish trace */
PHP_RSHUTDOWN_FUNCTION(codetracer)
{
    /* Restore the SAPI ub_write hook FIRST so any cleanup writes
     * during writer teardown go to the original handler. */
    if (original_sapi_ub_write) {
        sapi_module.ub_write = original_sapi_ub_write;
        original_sapi_ub_write = NULL;
    }

    if (trace_writer) {
        /* Pair the synthetic <toplevel> Call registered in RINIT with a
         * Return so call_exit ordering stays balanced. */
        trace_writer_register_return(trace_writer);

        trace_writer_finish_events(trace_writer);
        /* Write the branded recorder-id field into `meta.dat` (CTFS
         * spec §7) before closing the writer. */
        {
            const char recorder_id[] = "codetracer-php-recorder";
            ct_write_meta_dat(trace_writer,
                              (const uint8_t *)recorder_id,
                              sizeof(recorder_id) - 1);
        }
        /* trace_writer_close flushes the in-memory CTFS container and
         * actually writes the .ct file to disk.  In the Nim FFI this is
         * NOT done by trace_writer_free — the close + free split mirrors
         * the rust FFI's contract (and matches tests/test_ffi.c in the
         * Nim repo).  Without this call the trace dir stays empty even
         * though every other FFI call returns success. */
        trace_writer_close(trace_writer);
        trace_writer_free(trace_writer);
        trace_writer = NULL;
    }
    tracing_enabled = 0;
    in_io_hook = 0;
    in_trace_hook = 0;
    if (output_dir) {
        free(output_dir);
        output_dir = NULL;
    }
    return SUCCESS;
}

/* phpinfo() output */
PHP_MINFO_FUNCTION(codetracer)
{
    php_info_print_table_start();
    php_info_print_table_row(2, "CodeTracer support", "enabled");
    php_info_print_table_row(2, "Version", PHP_CODETRACER_VERSION);
    php_info_print_table_end();
}

/* Module entry */
zend_module_entry codetracer_module_entry = {
    STANDARD_MODULE_HEADER,
    PHP_CODETRACER_EXTNAME,
    NULL,                    /* functions */
    PHP_MINIT(codetracer),
    PHP_MSHUTDOWN(codetracer),
    PHP_RINIT(codetracer),
    PHP_RSHUTDOWN(codetracer),
    PHP_MINFO(codetracer),
    PHP_CODETRACER_VERSION,
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_CODETRACER
ZEND_GET_MODULE(codetracer)
#endif
