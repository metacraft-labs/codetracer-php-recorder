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
static TraceWriterHandle *trace_writer = NULL;
static int tracing_enabled = 0;
static int in_trace_hook = 0;  /* Prevent recursive tracing */
static char *output_dir = NULL;

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

    /* Register the call */
    if (func_name && file_name) {
        uintptr_t fid = trace_writer_ensure_function_id(
            trace_writer, func_name, file_name, (int64_t)line_no);
        trace_writer_register_call(trace_writer, fid);
        trace_writer_register_step(trace_writer, file_name, (int64_t)line_no);
        have_info = 1;
    }

    in_trace_hook = 0;

    /* Call original handler (executes the actual PHP code) */
    original_zend_execute_ex(execute_data);

    /* After return — use have_info since func_name may be invalidated */
    if (tracing_enabled && !in_trace_hook && trace_writer && have_info) {
        in_trace_hook = 1;
        trace_writer_register_return(trace_writer);
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
    const char *out = getenv("CODETRACER_OUTPUT_DIR");
    if (!out) out = "/tmp/codetracer_traces";

    const char *enabled_env = getenv("CODETRACER_ENABLED");
    if (!enabled_env || strcmp(enabled_env, "1") != 0) {
        tracing_enabled = 0;
        return SUCCESS;
    }

    /* Create output directory for this request */
    char trace_dir[4096];
    snprintf(trace_dir, sizeof(trace_dir), "%s/%ld_%d",
            out, (long)time(NULL), (int)getpid());
    mkdir(out, 0755);
    mkdir(trace_dir, 0755);

    /* Create trace writer */
    const char *script = SG(request_info).path_translated;
    if (!script) script = "php";

    trace_writer = trace_writer_new(script, FMT_BINARY);
    if (!trace_writer) {
        return SUCCESS;
    }

    /* Begin writing trace files */
    char path_buf[4096];

    snprintf(path_buf, sizeof(path_buf), "%s/trace_metadata.json", trace_dir);
    trace_writer_begin_metadata(trace_writer, path_buf);

    snprintf(path_buf, sizeof(path_buf), "%s/trace.bin", trace_dir);
    trace_writer_begin_events(trace_writer, path_buf);

    snprintf(path_buf, sizeof(path_buf), "%s/trace_paths.json", trace_dir);
    trace_writer_begin_paths(trace_writer, path_buf);

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

    /* Register start — must be called after TK_NONE is registered */
    if (script) {
        trace_writer_start(trace_writer, script, 1);
    }

    tracing_enabled = 1;
    output_dir = strdup(trace_dir);

    return SUCCESS;
}

/* Request shutdown — finish trace */
PHP_RSHUTDOWN_FUNCTION(codetracer)
{
    if (trace_writer) {
        trace_writer_finish_events(trace_writer);
        trace_writer_finish_metadata(trace_writer);
        trace_writer_finish_paths(trace_writer);
        trace_writer_free(trace_writer);
        trace_writer = NULL;
    }
    tracing_enabled = 0;
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
