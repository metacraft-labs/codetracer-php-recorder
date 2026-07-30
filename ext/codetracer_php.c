#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_ini.h"
#include "ext/standard/info.h"
#include "SAPI.h"
#include "php_codetracer.h"
#include "Zend/zend_signal.h"
#include "codetracer_trace_writer.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <unistd.h>
#include <sys/stat.h>
#include <sys/types.h>
#include <stdarg.h>
#include <signal.h>
#include <ucontext.h>

/* Global state */
static void (*original_zend_execute_ex)(zend_execute_data *execute_data);
static size_t (*original_sapi_ub_write)(const char *str, size_t str_length) = NULL;
static TraceWriterHandle *trace_writer = NULL;
static int tracing_enabled = 0;
static int in_trace_hook = 0;  /* Prevent recursive tracing */
static int in_io_hook = 0;     /* Prevent recursive IO capture */
static char *output_dir = NULL;

/* ==========================================================================
 * RS-M7 — one continuous recording per WORKER, partitioned by request spans.
 *
 * Before RS-M7 this extension opened a trace writer in PHP_RINIT and called
 * trace_writer_finish_events + trace_writer_free in PHP_RSHUTDOWN: one
 * complete recording per request, torn down each time, each one re-interning
 * the whole application's paths and function names.
 *
 * `CTFS-Request-Span-Streams.md` § "Requests and Processes Are the Same
 * Interval Model" resolves that:
 *
 *     process : container :: request : process
 *
 * so a PHP worker is ONE continuous recording whose timeline is partitioned
 * by request spans.  The writer therefore lives for the module's lifetime
 * (torn down in PHP_MSHUTDOWN, never in PHP_RSHUTDOWN) and each request
 * appends a `web-request` span over that shared timeline.  The interning
 * tables are container-wide, so the application's paths and function names
 * are interned ONCE per worker instead of once per request.
 *
 * The state below is held in a `pemalloc`-ed struct rather than in
 * request-scoped `emalloc` memory: request-scoped allocations are released
 * wholesale at the end of every request, which is exactly the lifetime this
 * milestone is moving away from.
 *
 * WHEN THE WRITER IS OPENED.  The lifetime is module-scope, but the *moment*
 * of creation is deliberately not unconditionally PHP_MINIT:
 *
 *   - Under `fpm-fcgi`, PHP_MINIT runs in the pool MASTER, before it forks
 *     its workers.  A writer created there would be inherited by every
 *     child; they would all buffer into their fork-copied heaps and all try
 *     to write the same container path.  Each FPM worker therefore opens its
 *     OWN writer on its first request, detected by a PID change.
 *   - Under `cli`, PHP_MINIT runs before the script path is known
 *     (`SG(request_info).path_translated` is NULL), and the container's file
 *     name is derived from the program name.  CLI recordings keep their
 *     current `<script>.ct` name by opening on the first request too.
 *   - When `CODETRACER_PROGRAM` names the program up front (what the web
 *     harness and the demo recipes do), there is nothing left to wait for and
 *     the writer is opened in PHP_MINIT proper — the built-in server path.
 *
 * In every case the writer is opened AT MOST ONCE PER PROCESS and closed in
 * PHP_MSHUTDOWN.  A worker that is SIGKILLed never reaches PHP_MSHUTDOWN; see
 * the comment on ct_worker_close() for what that costs.
 * ========================================================================== */

typedef struct _ct_worker_state {
    /* --- worker (module) lifetime ------------------------------------- */
    pid_t    owner_pid;      /* process that opened `writer`; 0 = not open  */
    int      writer_ready;   /* begin_events succeeded                      */
    int      requests_seen;  /* PHP_RINITs served by THIS process           */
    volatile sig_atomic_t shutdown_requested; /* set from a signal handler   */
    /*
     * "The request path owns the writer right now."
     *
     * THE flag the shutdown signal handler consults, and the reason it is a
     * `volatile sig_atomic_t` and not an `int`: C11 §7.14.1.1p5 lets a signal
     * handler touch exactly two kinds of object, and this is one of them.
     *
     * It is set at the TOP of the recording path in PHP_RINIT — before
     * `ct_worker_open()`, before `ensure_function_id`, before `register_call`
     * — and cleared in PHP_RSHUTDOWN only AFTER the last writer call of the
     * request (`ct_span_settle()`'s `register_span`) has returned.  So it
     * brackets EVERY mutation of the writer, not just the part of the request
     * during which a span happens to be open.
     *
     * It is deliberately NOT `span_active`: a CLI run mutates the writer in
     * exactly the same way but must never register a `web-request` span (see
     * `ct_is_http_request()`), so the two conditions are genuinely different
     * and one flag cannot mean both.
     */
    volatile sig_atomic_t writer_busy;
    uint64_t next_span_id;   /* 1-based, monotonic WITHIN the container     */

    /* --- per-request state --------------------------------------------
     * Scoped to one PHP_RINIT/PHP_RSHUTDOWN pair.  PHP never nests one
     * request inside another within a process (unlike Rack, where a
     * mounted sub-app re-enters the middleware and a thread-local "current
     * span" silently loses one), so the request/response pair IS the
     * enclosing frame here.  `span_active` still guards the settle path so
     * a second RSHUTDOWN can never append a duplicate record.
     *
     * `volatile sig_atomic_t` rather than `int` because its lifetime is
     * nested inside `writer_busy`'s and the invariant "span_active implies
     * writer_busy" is one the signal path relies on; keeping both types the
     * same removes any question of a torn or reordered read.
     */
    volatile sig_atomic_t span_active;
    uint64_t span_id;
    uint64_t start_step;
    uint64_t start_wall_ns;
    uint64_t start_mono_ns;
    uint64_t response_bytes;
    char    *http_method;    /* pemalloc'd, NULL when absent               */
    char    *http_url;
    char    *http_remote_addr;

    /* Extra metadata contributed by userland (framework middleware):
     * http.route, framework, error.message, ...  Parallel arrays, order
     * preserved, because metadata ORDER is part of the wire contract. */
    char   **extra_keys;
    char   **extra_values;
    size_t   extra_count;
    size_t   extra_capacity;
} ct_worker_state;

static ct_worker_state *ct_state = NULL;  /* pemalloc'd in PHP_MINIT */

#define CT_SPAN_TYPE_WEB_REQUEST "web-request"

/* Maximum length for serialized string values */
#define MAX_VALUE_REPR_LEN 1024

/* --------------------------------------------------------------------------
 * Small helpers over the persistent state
 * -------------------------------------------------------------------------- */

/*
 * Diagnostics for the worker-lifetime path, enabled with CODETRACER_DEBUG=1.
 *
 * Worth having permanently: everything interesting about RS-M7 happens in
 * PHP_MINIT / PHP_MSHUTDOWN of a process nobody is attached to (an FPM child
 * forked by a master the recorder did not spawn), and the failure mode is a
 * silently missing container rather than an error.
 */
static void ct_debug(const char *fmt, ...)
{
    static int checked = 0, on = 0;
    static const char *sink = NULL;
    va_list ap;
    FILE *out;
    if (!checked) {
        const char *e = getenv("CODETRACER_DEBUG");
        on = e && e[0] != '\0' && strcmp(e, "0") != 0;
        /* CODETRACER_DEBUG=1 goes to stderr; anything else is taken as a file
         * path.  A file is what you want under PHP-FPM, whose master swallows
         * or reframes its children's stderr. */
        if (on && strcmp(e, "1") != 0) sink = e;
        checked = 1;
    }
    if (!on) return;
    out = sink ? fopen(sink, "a") : stderr;
    if (!out) return;
    fprintf(out, "[codetracer pid=%d] ", (int)getpid());
    va_start(ap, fmt);
    vfprintf(out, fmt, ap);
    va_end(ap);
    fputc('\n', out);
    fflush(out);
    if (sink) fclose(out);
}

static uint64_t ct_wall_ns(void)
{
    struct timespec ts;
    if (clock_gettime(CLOCK_REALTIME, &ts) != 0) return 0;
    return (uint64_t)ts.tv_sec * 1000000000ULL + (uint64_t)ts.tv_nsec;
}

static uint64_t ct_mono_ns(void)
{
    struct timespec ts;
    if (clock_gettime(CLOCK_MONOTONIC, &ts) != 0) return 0;
    return (uint64_t)ts.tv_sec * 1000000000ULL + (uint64_t)ts.tv_nsec;
}

/* strdup into persistent (non-request) memory. */
static char *ct_pstrdup(const char *s)
{
    size_t len;
    char *out;
    if (!s) return NULL;
    len = strlen(s);
    out = (char *)pemalloc(len + 1, 1);
    if (!out) return NULL;
    memcpy(out, s, len + 1);
    return out;
}

static void ct_pfree_str(char **slot)
{
    if (slot && *slot) {
        pefree(*slot, 1);
        *slot = NULL;
    }
}

static void ct_clear_extra_metadata(ct_worker_state *st)
{
    size_t i;
    for (i = 0; i < st->extra_count; i++) {
        ct_pfree_str(&st->extra_keys[i]);
        ct_pfree_str(&st->extra_values[i]);
    }
    st->extra_count = 0;
}

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
    /* Response size for the request span's `http.response_size`.  Every
     * stdout-bound byte of a web response goes through ub_write, so counting
     * here is exact and costs one add — no Content-Length header required,
     * which matters because the built-in server rarely sets one. */
    if (ct_state && ct_state->span_active && str_length > 0) {
        ct_state->response_bytes += (uint64_t)str_length;
    }
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

/* --------------------------------------------------------------------------
 * Worker recording lifetime
 * -------------------------------------------------------------------------- */

static int ct_tracing_requested(void)
{
    const char *enabled_env = getenv("CODETRACER_ENABLED");
    return enabled_env && strcmp(enabled_env, "1") == 0;
}

static int ct_is_fpm(void)
{
    return sapi_module.name && strcmp(sapi_module.name, "fpm-fcgi") == 0;
}

/*
 * Resolve this worker's trace directory into `buf`.
 *
 * CODETRACER_TRACE_DIR names the directory verbatim.  Because the container's
 * file name is derived from the program name, two processes pointed at the
 * same CODETRACER_TRACE_DIR would collide — so this form is for single-process
 * recordings (CLI, `php -S`), which is also how the existing e2e suite drives
 * the recorder.
 *
 * Otherwise the worker gets its own subdirectory of CODETRACER_OUTPUT_DIR,
 * named after its pid.  That is what makes a PHP-FPM pool work: every worker
 * owns one directory holding one container.
 */
static void ct_resolve_trace_dir(char *buf, size_t buf_len)
{
    const char *explicit_trace_dir = getenv("CODETRACER_TRACE_DIR");
    if (explicit_trace_dir && explicit_trace_dir[0] != '\0') {
        snprintf(buf, buf_len, "%s", explicit_trace_dir);
        mkdir(buf, 0755);
        return;
    }
    {
        const char *out = getenv("CODETRACER_OUTPUT_DIR");
        if (!out) out = "/tmp/codetracer_traces";
        mkdir(out, 0755);
        snprintf(buf, buf_len, "%s/worker_%d", out, (int)getpid());
        mkdir(buf, 0755);
    }
}

/*
 * Open this process's writer.  Idempotent per process; safe to call from
 * PHP_MINIT (when the program name is known up front) or from the first
 * PHP_RINIT this process serves.
 */
static void ct_worker_open(const char *program)
{
    char trace_dir[4096];
    char path_buf[4096];
    char cwd[4096];

    if (!ct_state) return;
    if (trace_writer && ct_state->owner_pid == getpid()) return;

    if (trace_writer && ct_state->owner_pid != getpid()) {
        /* We are a forked child of the process that opened this writer —
         * PHP-FPM's master runs PHP_MINIT and only then forks its pool.  The
         * inherited handle points into our own fork-copied heap, so freeing
         * it is safe and closing it is NOT: closing would write the master's
         * (empty) container from every worker.  Drop it and open our own. */
        trace_writer_free(trace_writer);
        trace_writer = NULL;
        ct_state->writer_ready = 0;
        ct_state->next_span_id = 1;
        ct_state->requests_seen = 0;
    }

    ct_resolve_trace_dir(trace_dir, sizeof(trace_dir));

    /* CTFS V4 multi-stream is the canonical CodeTracer trace format
     * — see `metacraft-specs/policies/recorder-test-requirements.md`
     * §1 and `Recorder-CLI-Conventions.md` §4.  We pass `FMT_CTFS`
     * (alias for the Nim FFI's `FFI_TRACE_FORMAT_BINARY`, value 2):
     * the Nim writer interprets that as multi-stream V4 (steps.dat /
     * calls.dat / values.dat / paths.dat / meta.dat / etc.), which is
     * the layout `ct print --full` decodes directly. */
    trace_writer = trace_writer_new(program, FMT_CTFS);
    if (!trace_writer) {
        ct_debug("trace_writer_new failed: %s", trace_writer_last_error());
        return;
    }

    /* Begin writing the events stream.  The Nim multi-stream writer
     * derives the `.ct` container path from the *program* name and the
     * events path's directory; metadata and paths streams are written into
     * the container on close. */
    snprintf(path_buf, sizeof(path_buf), "%s/trace.bin", trace_dir);
    if (trace_writer_begin_events(trace_writer, path_buf) != 0) {
        ct_debug("begin_events(%s) failed: %s", path_buf,
                 trace_writer_last_error());
        trace_writer_free(trace_writer);
        trace_writer = NULL;
        return;
    }

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

    /* Register start — must be called after TK_NONE is registered.  Once per
     * WORKER now, not once per request: the timeline is continuous. */
    trace_writer_start(trace_writer, program, 1);

    if (output_dir) { free(output_dir); }
    output_dir = strdup(trace_dir);

    ct_state->owner_pid = getpid();
    ct_state->writer_ready = 1;
    ct_debug("writer open: program=%s dir=%s", program, trace_dir);
    ct_state->next_span_id = 1;
    tracing_enabled = 1;

    /* Install the SAPI unbuffered-write hook for stdout capture.  Unlike
     * before RS-M7 this is installed once per worker and left in place: it is
     * process-global SAPI state, and the recording it feeds now spans every
     * request the worker serves. */
    if (!original_sapi_ub_write) {
        original_sapi_ub_write = sapi_module.ub_write;
        sapi_module.ub_write = codetracer_sapi_ub_write;
    }
}

/*
 * Close this worker's recording and write the container.
 *
 * Called from PHP_MSHUTDOWN, i.e. once per worker.  A worker that exits
 * gracefully — the CLI process finishing, `php -S` being interrupted, an FPM
 * child reaching its `pm.max_requests` or being asked to quit — reaches this
 * and its whole recording lands.
 *
 * A worker that is SIGKILLed does not.  The Nim multi-stream writer builds the
 * container in memory and `trace_writer_close` is what writes the `.ct` file
 * (the FFI documents this on `trace_writer_flush_spans`: "the .ct file is
 * written only by trace_writer_close, so nothing appears on disk mid-session"),
 * so a hard-killed worker loses its ENTIRE recording, not only the in-flight
 * request.  Surviving that needs a streaming writer plus the ring-buffer
 * drainer of `CTFS-Request-Span-Streams.md` § "Writing from many processes";
 * neither exists in the FFI today.  See tests/test_web_integration.php.
 */
/*
 * `volatile sig_atomic_t`, not `int`: ct_worker_close() runs both from the
 * request path (PHP_MSHUTDOWN, atexit, PHP_RSHUTDOWN) and from the shutdown
 * signal handler, so this guard is read and written from a signal context.
 */
static volatile sig_atomic_t ct_closed = 0;

static void ct_worker_close(void)
{
    sigset_t stop_signals, previous_mask;
    int mask_installed;

    if (ct_closed) return;
    ct_closed = 1;

    /*
     * The close is UNINTERRUPTIBLE by a stop signal.
     *
     * Writing the container is the one part of this recorder that cannot be
     * retried: `trace_writer_close()` is what puts the whole in-memory CTFS
     * container on disk, so a stop signal landing halfway through loses
     * everything the worker recorded, not a suffix of it.
     *
     * That is not hypothetical.  Under php-fpm's DEFAULT
     * `process_control_timeout = 0` the master does not pace its escalation
     * at all; `fpm_pctl_action_next()` sends
     *
     *     kill(child, SIGQUIT)      <- this close starts
     *     kill(child, SIGTERM)      <- lands mid-close; our SIGTERM chain
     *                                  ends at SIG_DFL, so the worker dies
     *
     * with a zero timeout between the two.
     *
     * Which callers this block actually protects, measured with a probe that
     * reports the effective mask on entry here (php-fpm 8.4.21, four static
     * workers, default control timeout):
     *
     *   reached from ct_shutdown_signal_handler   SIGQUIT+SIGTERM ALREADY
     *                                             blocked, but not by us
     *   reached from PHP_MSHUTDOWN / ct_atexit    nothing blocked
     *   reached from PHP_RSHUTDOWN, after the
     *     handler deferred via shutdown_requested nothing blocked
     *
     * The first row is Zend's doing: `zend_sigaction()` installs
     * `zend_signal_handler_defer` with `sa_mask = global_sigmask`, which is
     * `sigfillset()` minus a dozen synchronous/fatal signals — SIGQUIT and
     * SIGTERM stay in it.  So on the signal path this block is REDUNDANT, and
     * removing it does not make
     * `php_fpm_default_control_timeout_still_writes_every_container` fail
     * (measured: 4/4 containers in 5/5 runs with the block deleted).  The
     * other two rows are what need it: they run in ordinary context with
     * nothing blocked, and that is where the escalation above kills a worker
     * mid-write.
     *
     * `sigprocmask()` rather than the handler's own `sa_mask` because
     * `zend_sigaction()` DISCARDS the `sa_mask` we hand it (see
     * ct_arm_shutdown_signals) — it keeps only our handler pointer and flags.
     * It is async-signal-safe, so it is legal on the path that reaches this
     * from the handler.  The pending signal is delivered the moment the mask
     * is restored, so nothing is swallowed — the stop is delayed by exactly
     * one close.
     *
     * Single exit path below on purpose: the only `return` in this function
     * is the `ct_closed` guard, above the SIG_BLOCK.
     */
    sigemptyset(&stop_signals);
    sigaddset(&stop_signals, SIGQUIT);
    sigaddset(&stop_signals, SIGTERM);
    mask_installed = (sigprocmask(SIG_BLOCK, &stop_signals, &previous_mask) == 0);

    ct_debug("worker close: writer=%p owner_pid=%d requests=%d",
             (void *)trace_writer,
             ct_state ? (int)ct_state->owner_pid : -1,
             ct_state ? ct_state->requests_seen : -1);
    if (original_sapi_ub_write) {
        sapi_module.ub_write = original_sapi_ub_write;
        original_sapi_ub_write = NULL;
    }

    if (trace_writer) {
        if (ct_state && ct_state->owner_pid == getpid() &&
            ct_state->requests_seen > 0) {
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
             * NOT done by trace_writer_free. */
            if (trace_writer_close(trace_writer) != 0) {
                ct_debug("trace_writer_close failed: %s",
                         trace_writer_last_error());
            } else {
                ct_debug("container written under %s",
                         output_dir ? output_dir : "?");
            }
        }
        /* A writer this process inherited across fork, or one that served no
         * request at all (the FPM master), is freed WITHOUT being closed so it
         * cannot write a stray empty container. */
        trace_writer_free(trace_writer);
        trace_writer = NULL;
    }
    tracing_enabled = 0;
    in_io_hook = 0;
    in_trace_hook = 0;
    if (ct_state) ct_state->writer_ready = 0;
    if (output_dir) {
        free(output_dir);
        output_dir = NULL;
    }

    /* The container is on disk; a stop signal may now proceed. */
    if (mask_installed) sigprocmask(SIG_SETMASK, &previous_mask, NULL);
}

/* --------------------------------------------------------------------------
 * Flush on worker exit
 * -------------------------------------------------------------------------- */

static struct sigaction ct_prev_quit, ct_prev_term;
static int ct_signals_installed = 0;

/*
 * Close the recording when the pool asks this worker to stop.
 *
 * Measured behaviour of php-fpm 8.4 (with CODETRACER_DEBUG): on a graceful
 * pool stop only ONE of three pool children ever reached
 * `php_module_shutdown()`; the rest left without running PHP_MSHUTDOWN and
 * without running `atexit` handlers either.  Since the CTFS multi-stream
 * writer only puts bytes on disk in `trace_writer_close()`, that lost those
 * workers' whole recordings.
 *
 * The signal FPM uses to say "finish and go" is SIGQUIT (SIGTERM for a hard
 * stop), so that is where the container gets written.  Two rules keep this
 * out of trouble:
 *
 *   - If the request path owns the writer (`writer_busy`), the handler only
 *     records that shutdown was asked for.  PHP_RSHUTDOWN settles the span
 *     and closes then, so the writer is never mutated from a signal context
 *     while the request path is in the middle of mutating it.
 *   - If the worker is idle (the normal case: it is blocked in `accept()`),
 *     the close happens here.  Nothing else is touching the writer.
 *
 * The predicate is `writer_busy`, NOT `span_active`.  `span_active` is 0 for
 * three stretches during which the writer is very much being mutated, and a
 * close in any of them frees the writer underneath the request path:
 *
 *   1. PHP_RINIT from its first statement until `ct_span_begin()` — the
 *      `ct_worker_open()`, `ensure_function_id` and `register_call` calls.
 *   2. The whole body of a CLI (non-HTTP) run, which never opens a span at
 *      all yet records every step of the script.
 *   3. PHP_RSHUTDOWN between `ct_span_settle()` clearing the flag and its
 *      `trace_writer_register_span()` returning.
 *
 * `writer_busy` spans all three: RINIT sets it before touching the writer and
 * RSHUTDOWN clears it after the last writer call has returned.
 *
 * The previously installed handler is always chained, so FPM's own shutdown
 * logic still runs exactly as it would have.
 *
 * One caveat on the `ct_debug()` below, since the `writer_busy` comment cites
 * C11 §7.14.1.1p5 and a reader could infer more rigour than is here: `ct_debug`
 * goes through `getenv`/`fopen`/`fprintf`, none of which are async-signal-safe.
 * It is kept anyway because it is the only instrument that answers "did this
 * handler actually run?" — the question a previous unreproducible measurement
 * of this code turned on — and because `ct_worker_close()` already calls it on
 * this same path.  It is inert unless CODETRACER_DEBUG is set, so no
 * production run takes the risk.
 */
static void ct_shutdown_signal_handler(int signo)
{
    int saved_errno = errno;
    struct sigaction *prev = (signo == SIGQUIT) ? &ct_prev_quit : &ct_prev_term;

    ct_debug("stop signal %d: state=%p writer_busy=%d closed=%d", signo,
             (void *)ct_state,
             ct_state ? (int)ct_state->writer_busy : -1, (int)ct_closed);
    if (ct_state && !ct_state->writer_busy) {
        ct_worker_close();
    } else if (ct_state) {
        ct_state->shutdown_requested = 1;
    }

    errno = saved_errno;

    if (prev->sa_flags & SA_SIGINFO) {
        /*
         * Chain into an SA_SIGINFO handler with SYNTHESISED arguments rather
         * than with NULL.  A three-argument handler is entitled to
         * dereference both of them — `info->si_code`, `info->si_pid` and
         * `uc->uc_sigmask` are ordinary things for one to read — and passing
         * NULL would fault it inside a signal context, turning a graceful
         * stop into a SIGSEGV.  Zeroed structures read as "no information"
         * instead, which is a value every handler can cope with.
         *
         * Unreachable with php-fpm today (it installs plain `sa_handler`
         * handlers), which is exactly why it must not be left as a trap for
         * whatever installs a handler here next.
         */
        if (prev->sa_sigaction) {
            siginfo_t info;
            ucontext_t uctx;
            memset(&info, 0, sizeof(info));
            memset(&uctx, 0, sizeof(uctx));
            info.si_signo = signo;
            /* What the kernel reports for a kill(2)-delivered signal, which
             * is what this is from the chained handler's point of view. */
            info.si_code = SI_USER;
            info.si_pid = getpid();
            info.si_uid = getuid();
            prev->sa_sigaction(signo, &info, &uctx);
        }
    } else if (prev->sa_handler == SIG_DFL) {
        struct sigaction dfl;
        memset(&dfl, 0, sizeof(dfl));
        dfl.sa_handler = SIG_DFL;
        sigaction(signo, &dfl, NULL);
        raise(signo);
    } else if (prev->sa_handler != SIG_IGN) {
        prev->sa_handler(signo);
    }
}

/*
 * Arm the stop-signal handlers for this request.
 *
 * Called from EVERY PHP_RINIT, and through `zend_sigaction()` rather than
 * `sigaction()`.  Both of those are forced by how Zend owns the signal slots
 * (`Zend/zend_signal.c`):
 *
 *   - `zend_signal_activate()`, which runs at every request start, begins with
 *     `memcpy(&SIGG(handlers), &global_orig_handlers, ...)` — it restores the
 *     table of "handlers to forward to" from a snapshot taken at PROCESS
 *     STARTUP by `zend_signal_init()`.  Under FPM that snapshot is taken in
 *     the CHILD, at the end of `fpm_signals_init_child()`, which is why it
 *     holds the child's own handlers (see below) and not the master's.
 *     Either way, anything an extension put there afterwards is dropped.
 *   - It then calls `zend_signal_register(sig, zend_signal_handler_defer)`,
 *     which FIRST checks whether `zend_signal_handler_defer` is already the
 *     installed handler and returns early if so — WITHOUT re-saving what is
 *     there.
 *
 * A one-shot raw `sigaction()` from the first PHP_RINIT therefore survives
 * exactly two requests: request 2's activate saves it (our handler is the
 * installed one at that moment), and request 3's activate wipes it and then
 * declines to save anything because `defer` is already installed.  From the
 * third request on the worker has no CodeTracer stop handler at all, and a
 * pool stop loses its whole recording.
 *
 * Measured with the one-shot install, four static workers, graceful pool stop
 * under the default control timeout, counting entries to the handler:
 *
 *   1 request per worker    4/4 containers,      4 handler entries
 *   2 requests per worker   4/4 containers, 24599 handler entries  (!)
 *   3 requests per worker   1/4 containers, 18497 handler entries  (!)
 *   6 requests per worker   0/4 containers,      0 handler entries
 *
 * The runaway counts are the second half of the same bug.  At the request-2
 * activate our handler is what `zend_signal_register()` saves into
 * `SIGG(handlers)`, while `ct_prev_quit` still holds the `defer` entry the
 * one-shot `sigaction()` displaced — so chaining ran defer, which dispatched
 * straight back to us.  Arming through `zend_sigaction()` cannot produce that
 * cycle: `oldact` comes out of `SIGG(handlers)`, which is where Zend forwards
 * from, so it can never name this function.  With the arming below the same
 * runs give 4/4 containers and exactly 2 entries per worker (SIGQUIT then
 * SIGTERM, the second short-circuiting on `ct_closed`).
 *
 * `zend_sigaction()` is the supported way in.  It writes our handler into
 * `SIGG(handlers)` and leaves `zend_signal_handler_defer` installed, so Zend
 * forwards the signal to us; `oldact` yields whatever Zend would have called
 * instead (php-fpm's `sig_soft_quit` for SIGQUIT, SIG_DFL for SIGTERM), which
 * is what we chain to.  Re-arming per request is what keeps it in place
 * across the per-request `memcpy`.  On a PHP built without ZEND_SIGNALS
 * `Zend/zend_signal.h` maps `zend_sigaction` straight to `sigaction`, so the
 * same code is correct there too.
 *
 * Never from PHP_MINIT: under FPM that runs in the pre-fork master, and
 * `fpm_signals_init_child()` replaces the child's handlers after the fork.
 */
static void ct_arm_shutdown_signals(void)
{
    struct sigaction act, previous;

    memset(&act, 0, sizeof(act));
    memset(&previous, 0, sizeof(previous));
    act.sa_handler = ct_shutdown_signal_handler;
    act.sa_flags = SA_RESTART;
    /*
     * `sa_mask` is empty on purpose, and is NOT where the "a close is not
     * interrupted" guarantee comes from: on a ZEND_SIGNALS build
     * `zend_sigaction()` throws this field away and installs
     * `zend_signal_handler_defer` with `global_sigmask` instead, so whatever
     * we put here is never what the kernel applies.  That guarantee is the
     * `sigprocmask()` around the body of `ct_worker_close()` — see the
     * comment there.
     */
    sigemptyset(&act.sa_mask);

    zend_sigaction(SIGQUIT, &act, &previous);
    /*
     * Never chain to ourselves — a self-chain is unbounded recursion in a
     * signal context, and it is exactly what the pre-existing one-shot
     * `sigaction()` produced (~20k handler entries; see the table above).
     *
     * On a ZEND_SIGNALS build this is belt-and-braces: `oldact` is read out of
     * `SIGG(handlers)`, which the per-request `memcpy` has just reset to the
     * startup snapshot, so it cannot name this function.  On a build WITHOUT
     * ZEND_SIGNALS it is load-bearing: `zend_sigaction` is then plain
     * `sigaction`, so from the second request on `previous` IS this handler
     * every time, and the check is what preserves the real chain target.
     */
    if (previous.sa_handler != ct_shutdown_signal_handler) ct_prev_quit = previous;

    memset(&previous, 0, sizeof(previous));
    zend_sigaction(SIGTERM, &act, &previous);
    if (previous.sa_handler != ct_shutdown_signal_handler) ct_prev_term = previous;

    if (!ct_signals_installed) {
        ct_signals_installed = 1;
        ct_debug("shutdown signal handlers armed");
    }
}

/*
 * PHP-FPM children never run PHP_MSHUTDOWN.
 *
 * Verified against php-fpm 8.4 with CODETRACER_DEBUG: each pool child logs its
 * PHP_RINITs and its writer open, and then nothing — the master's graceful
 * SIGQUIT unwinds the child out of the FastCGI accept loop and the process
 * leaves without `php_module_shutdown()` ever being called.  Relying on
 * PHP_MSHUTDOWN alone therefore loses every FPM worker's container, which is
 * exactly the failure this recorder is here to avoid.
 *
 * `atexit` covers it: it fires on any `exit()`, whatever route the SAPI took
 * to get there, and `ct_worker_close()` is idempotent so the CLI and
 * built-in-server paths (which DO reach PHP_MSHUTDOWN) close exactly once.
 *
 * It does NOT cover a process killed by a signal — see the note on
 * ct_worker_close().
 */
static void ct_atexit(void)
{
    ct_debug("atexit");
    ct_worker_close();
}

/* --------------------------------------------------------------------------
 * Request spans
 * -------------------------------------------------------------------------- */

/* Fetch a string entry out of $_SERVER, or NULL. */
static const char *ct_server_var(const char *name)
{
    zval *server, *entry;
    zend_string *key;

    key = zend_string_init("_SERVER", sizeof("_SERVER") - 1, 0);
    zend_is_auto_global(key);
    server = zend_hash_find(&EG(symbol_table), key);
    zend_string_release(key);
    if (!server) return NULL;
    ZVAL_DEREF(server);
    if (Z_TYPE_P(server) != IS_ARRAY) return NULL;

    entry = zend_hash_str_find(Z_ARRVAL_P(server), name, strlen(name));
    if (!entry) return NULL;
    ZVAL_DEREF(entry);
    if (Z_TYPE_P(entry) != IS_STRING) return NULL;
    return Z_STRVAL_P(entry);
}

/*
 * Does this request look like an HTTP request?
 *
 * A CLI run has no request method, and giving it a `web-request` span would
 * both lie and change every existing CLI recording (a container that registers
 * no span is byte-identical to a pre-RS-M7 one — spec design goal 6).
 */
static int ct_is_http_request(void)
{
    return SG(request_info).request_method != NULL;
}

static void ct_span_begin(void)
{
    ct_worker_state *st = ct_state;
    const char *url;

    if (!st || !st->writer_ready || !trace_writer) return;
    if (!ct_is_http_request()) return;

    st->span_active = 1;
    st->span_id = st->next_span_id++;
    /* The step index MUST come from the writer, never from a count of our own
     * register_step calls: MultiStreamTraceWriter.stepCount advances for every
     * exec-stream event (column deltas, raise/catch, thread start/switch/exit),
     * so a self-maintained counter drifts and every row's double-click seeks to
     * the wrong place. */
    st->start_step = trace_writer_next_step_index(trace_writer);
    st->start_wall_ns = ct_wall_ns();
    st->start_mono_ns = ct_mono_ns();
    st->response_bytes = 0;
    ct_clear_extra_metadata(st);

    ct_pfree_str(&st->http_method);
    ct_pfree_str(&st->http_url);
    ct_pfree_str(&st->http_remote_addr);
    st->http_method = ct_pstrdup(SG(request_info).request_method);
    url = SG(request_info).request_uri;
    if (!url || url[0] == '\0') url = SG(request_info).path_translated;
    st->http_url = ct_pstrdup(url ? url : "/");
}

/*
 * Publish the in-flight record.
 *
 * The stream is append-only: an OPEN record now, a settled record with the
 * same span_id when the request finishes, and readers apply last-record-wins
 * (`CTFS-Request-Span-Streams.md` § Open records).  A PHP worker is a
 * long-lived server, which is the case the spec recommends open records for —
 * the latency of a slow request is exactly what a live panel wants to show —
 * and it keeps this recorder's stream the same shape as the Python and Ruby
 * ones for the cross-language conformance suite.
 */
static void ct_span_publish_open(void)
{
    ct_worker_state *st = ct_state;
    const char *keys[4];
    const char *values[4];
    char label[1024];

    if (!st || !st->span_active || !trace_writer) return;

    keys[0] = "http.method";      values[0] = st->http_method ? st->http_method : "";
    keys[1] = "http.url";         values[1] = st->http_url ? st->http_url : "";
    keys[2] = "http.status_code"; values[2] = "0";
    keys[3] = "http.duration_ms"; values[3] = "0";

    snprintf(label, sizeof(label), "%s %s",
             st->http_method ? st->http_method : "?",
             st->http_url ? st->http_url : "/");

    trace_writer_register_span(
        trace_writer,
        st->span_id,
        0,
        (uint8_t)SPAN_FLAG_OPEN,
        (uint8_t)SPAN_STATUS_UNKNOWN,
        st->start_wall_ns,
        0,                       /* end_wall_ns is 0 while open */
        0, 0,
        st->start_step,
        0,                       /* end_step is 0 while open    */
        NULL, NULL,
        CT_SPAN_TYPE_WEB_REQUEST,
        label,
        (uint8_t)SPAN_STRUCTURAL_SHARES_TIMELINE,
        keys, values, 4);
}

static void ct_span_settle(void)
{
    ct_worker_state *st = ct_state;
    const char *keys[16];
    const char *values[16];
    char status_buf[32], duration_buf[32], size_buf[32];
    char label[1024];
    uint64_t end_step, elapsed_ns, duration_ms;
    long status_code;
    uint8_t status;
    size_t n = 0, i;

    if (!st || !st->span_active) return;

    if (!trace_writer || !st->writer_ready) {
        st->span_active = 0;
        return;
    }

    /* `end_step` is the LAST step inside the span, one before the next index.
     * A request during which nothing was recorded collapses to the step it
     * started at rather than wrapping around to a huge range. */
    end_step = trace_writer_next_step_index(trace_writer);
    if (end_step <= st->start_step) {
        end_step = st->start_step;
    } else {
        end_step -= 1;
    }

    status_code = SG(sapi_headers).http_response_code;
    if (status_code <= 0) status_code = 200;  /* the SAPI default nobody set */
    if (status_code >= 400) status = (uint8_t)SPAN_STATUS_ERROR;
    else status = (uint8_t)SPAN_STATUS_OK;

    elapsed_ns = ct_mono_ns() - st->start_mono_ns;
    duration_ms = elapsed_ns / 1000000ULL;

    snprintf(status_buf, sizeof(status_buf), "%ld", status_code);
    snprintf(duration_buf, sizeof(duration_buf), "%llu",
             (unsigned long long)duration_ms);
    snprintf(size_buf, sizeof(size_buf), "%llu",
             (unsigned long long)st->response_bytes);

    /* ORDER IS PRESERVED end to end, so the well-known HTTP keys go in
     * display order — `CTFS-Request-Span-Streams.md` § Well-known metadata
     * keys, same order the Python and Ruby recorders emit. */
    keys[n] = "http.method";      values[n++] = st->http_method ? st->http_method : "";
    keys[n] = "http.url";         values[n++] = st->http_url ? st->http_url : "";
    keys[n] = "http.status_code"; values[n++] = status_buf;
    keys[n] = "http.duration_ms"; values[n++] = duration_buf;
    keys[n] = "http.response_size"; values[n++] = size_buf;
    if (st->http_remote_addr && st->http_remote_addr[0]) {
        keys[n] = "http.remote_addr"; values[n++] = st->http_remote_addr;
    }
    /* Userland annotations (http.route, framework, error.message) come last,
     * which is where a framework middleware's contribution belongs. */
    for (i = 0; i < st->extra_count && n < 16; i++) {
        keys[n] = st->extra_keys[i];
        values[n++] = st->extra_values[i];
    }

    snprintf(label, sizeof(label), "%s %s",
             st->http_method ? st->http_method : "?",
             st->http_url ? st->http_url : "/");

    trace_writer_register_span(
        trace_writer,
        st->span_id,
        0,                       /* parent_span_id — v1 spans are flat */
        0,                       /* flags: settled, inline binding      */
        status,
        st->start_wall_ns,
        st->start_wall_ns + elapsed_ns,
        0,                       /* process_ord: primary                */
        0,                       /* thread_id: PHP requests are single-threaded */
        st->start_step,
        end_step,
        NULL, NULL,              /* external binding unused: the steps are HERE */
        CT_SPAN_TYPE_WEB_REQUEST,
        label,
        (uint8_t)(SPAN_STRUCTURAL_CONTIGUOUS | SPAN_STRUCTURAL_SHARES_TIMELINE),
        keys, values, n);

    /* Cleared only NOW, after the last writer call of the span has returned.
     * Clearing it first (as this used to) opened a window in which
     * `codetracer_span_annotate()` would silently start refusing a span that
     * is still being written, and — before `writer_busy` existed — one in
     * which a stop signal freed the writer between the two calls above.
     * Still idempotent: a second settle finds the flag clear and returns. */
    st->span_active = 0;
}

/* --------------------------------------------------------------------------
 * Userland surface
 * -------------------------------------------------------------------------- */

/*
 * codetracer_span_annotate(string $key, string $value): bool
 *
 * Attach a metadata key to the request span currently being recorded — how a
 * framework middleware supplies `http.route`, `framework` or `error.message`,
 * which the extension cannot know on its own.  Returns false when nothing is
 * being recorded, so middleware can be installed unconditionally.
 */
PHP_FUNCTION(codetracer_span_annotate)
{
    char *key, *value;
    size_t key_len, value_len;
    ct_worker_state *st = ct_state;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STRING(key, key_len)
        Z_PARAM_STRING(value, value_len)
    ZEND_PARSE_PARAMETERS_END();

    if (!st || !st->span_active) RETURN_FALSE;
    if (st->extra_count >= st->extra_capacity) RETURN_FALSE;

    st->extra_keys[st->extra_count] = ct_pstrdup(key);
    st->extra_values[st->extra_count] = ct_pstrdup(value);
    if (!st->extra_keys[st->extra_count] || !st->extra_values[st->extra_count]) {
        ct_pfree_str(&st->extra_keys[st->extra_count]);
        ct_pfree_str(&st->extra_values[st->extra_count]);
        RETURN_FALSE;
    }
    st->extra_count++;
    RETURN_TRUE;
}

/* codetracer_trace_dir(): ?string — this worker's trace directory. */
PHP_FUNCTION(codetracer_trace_dir)
{
    ZEND_PARSE_PARAMETERS_NONE();
    if (!output_dir) RETURN_NULL();
    RETURN_STRING(output_dir);
}

/*
 * codetracer_spans_json(string $container, bool $settled = true): ?string
 *
 * Decode a finished container's span stream through `ct_spans_json` — the
 * canonical Nim reader, the same one `ct print -f http` uses.  Exposed so the
 * recorder's own tests assert on the spans they wrote without re-implementing
 * a decoder that could agree with a writer bug.
 */
PHP_FUNCTION(codetracer_spans_json)
{
    char *path;
    size_t path_len;
    zend_bool settled = 1;
    uint8_t *buf;
    size_t out_len = 0;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STRING(path, path_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_BOOL(settled)
    ZEND_PARSE_PARAMETERS_END();

    buf = ct_spans_json(path, settled ? 1 : 0, &out_len);
    if (!buf) RETURN_NULL();
    RETVAL_STRINGL((const char *)buf, out_len);
    ct_free_buffer(buf);
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_codetracer_span_annotate, 0, 0, 2)
    ZEND_ARG_INFO(0, key)
    ZEND_ARG_INFO(0, value)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_codetracer_trace_dir, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_codetracer_spans_json, 0, 0, 1)
    ZEND_ARG_INFO(0, container)
    ZEND_ARG_INFO(0, settled)
ZEND_END_ARG_INFO()

static const zend_function_entry codetracer_functions[] = {
    PHP_FE(codetracer_span_annotate, arginfo_codetracer_span_annotate)
    PHP_FE(codetracer_trace_dir,     arginfo_codetracer_trace_dir)
    PHP_FE(codetracer_spans_json,    arginfo_codetracer_spans_json)
    PHP_FE_END
};

/* --------------------------------------------------------------------------
 * Module / request lifecycle
 * -------------------------------------------------------------------------- */

/* Module initialization */
PHP_MINIT_FUNCTION(codetracer)
{
    const char *program;

    /* Save and replace zend_execute_ex */
    original_zend_execute_ex = zend_execute_ex;
    zend_execute_ex = codetracer_execute_ex;

    /* The recording state is PERSISTENT (`pemalloc(..., 1)`): it outlives
     * every request the worker serves, which is precisely the lifetime change
     * this milestone is about.  Request-scoped `emalloc` memory is released
     * wholesale at the end of each request. */
    ct_state = (ct_worker_state *)pemalloc(sizeof(ct_worker_state), 1);
    if (!ct_state) return SUCCESS;
    memset(ct_state, 0, sizeof(*ct_state));
    ct_state->next_span_id = 1;
    ct_state->extra_capacity = 8;
    ct_state->extra_keys =
        (char **)pecalloc(ct_state->extra_capacity, sizeof(char *), 1);
    ct_state->extra_values =
        (char **)pecalloc(ct_state->extra_capacity, sizeof(char *), 1);
    if (!ct_state->extra_keys || !ct_state->extra_values) {
        ct_state->extra_capacity = 0;
    }

    if (!ct_tracing_requested()) return SUCCESS;

    /* See ct_atexit(): PHP-FPM children never reach PHP_MSHUTDOWN. */
    atexit(ct_atexit);

    /* Open here when the program name is known up front and MINIT runs in the
     * process that will serve the requests — the built-in-server path.  See
     * the RS-M7 block at the top of this file for the two cases that must
     * wait for the first request instead. */
    program = getenv("CODETRACER_PROGRAM");
    if (program && program[0] && !ct_is_fpm()) {
        ct_worker_open(program);
    }

    return SUCCESS;
}

/* Module shutdown — this is where the worker's recording is written. */
PHP_MSHUTDOWN_FUNCTION(codetracer)
{
    ct_worker_close();

    if (ct_state) {
        size_t i;
        ct_clear_extra_metadata(ct_state);
        ct_pfree_str(&ct_state->http_method);
        ct_pfree_str(&ct_state->http_url);
        ct_pfree_str(&ct_state->http_remote_addr);
        for (i = 0; i < ct_state->extra_capacity; i++) {
            if (ct_state->extra_keys) ct_pfree_str(&ct_state->extra_keys[i]);
            if (ct_state->extra_values) ct_pfree_str(&ct_state->extra_values[i]);
        }
        if (ct_state->extra_keys) pefree(ct_state->extra_keys, 1);
        if (ct_state->extra_values) pefree(ct_state->extra_values, 1);
        pefree(ct_state, 1);
        ct_state = NULL;
    }

    /* Restore original handler */
    zend_execute_ex = original_zend_execute_ex;
    return SUCCESS;
}

/* Request initialization — open this request's span over the worker timeline */
PHP_RINIT_FUNCTION(codetracer)
{
    const char *script;
    ct_debug("RINIT sapi=%s writer=%p owner=%d",
             sapi_module.name ? sapi_module.name : "?",
             (void *)trace_writer, ct_state ? (int)ct_state->owner_pid : -1);

    if (!ct_tracing_requested() || !ct_state) {
        tracing_enabled = 0;
        return SUCCESS;
    }

    /* From here to the matching clear in PHP_RSHUTDOWN, THIS path owns the
     * writer.  A stop signal arriving inside the bracket only sets
     * `shutdown_requested`; the container is written from PHP_RSHUTDOWN once
     * the last writer call has returned.  The flag goes up BEFORE
     * `ct_worker_open()` so that even the open itself — which allocates the
     * writer and runs `begin_events` — cannot be freed out from under us. */
    ct_state->writer_busy = 1;

    /* Open the writer if this process does not have one yet.  Covers CLI (the
     * script path is only known now) and every PHP-FPM worker (PHP_MINIT ran
     * in the pre-fork master, so `owner_pid` names a different process). */
    if (!trace_writer || ct_state->owner_pid != getpid()) {
        const char *program = getenv("CODETRACER_PROGRAM");
        if (!program || !program[0]) {
            program = SG(request_info).path_translated;
        }
        if (!program || !program[0]) program = "php";
        ct_worker_open(program);
    }

    if (!trace_writer) return SUCCESS;

    /* Every request, not only the first: Zend resets the forwarding table at
     * each request start.  See ct_arm_shutdown_signals(). */
    ct_arm_shutdown_signals();

    ct_state->requests_seen++;
    tracing_enabled = 1;

    /* Register a synthetic <toplevel> Function + Call so the script's
     * top-level execution surfaces as a regular call frame.  Without this,
     * ct-print only shows internal function calls and hides the outermost
     * frame — every per-program test that asserts on call counts ends up
     * off-by-one.  This stays PER REQUEST: on a worker timeline each request
     * gets its own outermost frame.
     *
     * The paired Return is emitted in RSHUTDOWN so call_exit ordering stays
     * balanced. */
    script = SG(request_info).path_translated;
    if (!script) script = "php";
    {
        uintptr_t toplevel_fid = trace_writer_ensure_function_id(
            trace_writer, "<toplevel>", script, 1);
        trace_writer_register_call(trace_writer, toplevel_fid);
    }

    /* Open the span AFTER the toplevel call so `start_step` names the first
     * step of this request's own frame. */
    ct_span_begin();
    if (ct_state->span_active) {
        const char *remote = ct_server_var("REMOTE_ADDR");
        if (remote) ct_state->http_remote_addr = ct_pstrdup(remote);
        ct_span_publish_open();
    }

    return SUCCESS;
}

/* Request shutdown — settle this request's span.  The writer STAYS OPEN. */
PHP_RSHUTDOWN_FUNCTION(codetracer)
{
    if (trace_writer && ct_state && ct_state->writer_ready) {
        /* Pair the synthetic <toplevel> Call registered in RINIT with a
         * Return so call_exit ordering stays balanced. */
        trace_writer_register_return(trace_writer);
        ct_span_settle();
    }
    in_io_hook = 0;
    in_trace_hook = 0;
    /* The last writer call of this request has returned, so the request path
     * no longer owns the writer.  Cleared unconditionally: PHP_RINIT raises it
     * before the writer even exists and can leave early (no writer, tracing
     * turned off mid-flight), and a flag left raised would park the handler in
     * "defer" forever and lose the container. */
    if (ct_state) ct_state->writer_busy = 0;
    /* A stop signal arrived mid-request: the span is settled now, so this is
     * the first safe moment to write the container. */
    if (ct_state && ct_state->shutdown_requested) {
        ct_worker_close();
    }
    return SUCCESS;
}

/* phpinfo() output */
PHP_MINFO_FUNCTION(codetracer)
{
    php_info_print_table_start();
    php_info_print_table_row(2, "CodeTracer support", "enabled");
    php_info_print_table_row(2, "Version", PHP_CODETRACER_VERSION);
    php_info_print_table_row(2, "Recording scope", "worker (module lifetime)");
    php_info_print_table_end();
}

/* Module entry */
zend_module_entry codetracer_module_entry = {
    STANDARD_MODULE_HEADER,
    PHP_CODETRACER_EXTNAME,
    codetracer_functions,
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
