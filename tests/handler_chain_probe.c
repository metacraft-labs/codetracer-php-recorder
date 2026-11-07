/* Real PHP extension boundary probe, not a mock recorder or PHP runtime.
 * It installs genuine prior VM/exception callbacks, lets PHP execute the
 * unchanged fixtures, and verifies callback restoration during MSHUTDOWN.
 * This independent extension is necessary to observe coexistence with another
 * extension; invoking the recorder's private callbacks would not test it. */
#include "php.h"
#include "Zend/zend_exceptions.h"
#include "Zend/zend_vm_opcodes.h"
#include <stdio.h>
#include <stdlib.h>

static unsigned long yields, exceptions;
static user_opcode_handler_t preceding_yield;
static void (*preceding_exception)(zend_object *);

static int probe_yield(zend_execute_data *data)
{
    yields++;
    return preceding_yield ? preceding_yield(data) : ZEND_USER_OPCODE_DISPATCH;
}

static void probe_exception(zend_object *exception)
{
    exceptions++;
    if (preceding_exception) preceding_exception(exception);
}

PHP_MINIT_FUNCTION(handler_chain_probe)
{
    preceding_yield = zend_get_user_opcode_handler(ZEND_YIELD);
    if (zend_set_user_opcode_handler(ZEND_YIELD, probe_yield) != SUCCESS) return FAILURE;
    preceding_exception = zend_throw_exception_hook;
    zend_throw_exception_hook = probe_exception;
    return SUCCESS;
}

PHP_MSHUTDOWN_FUNCTION(handler_chain_probe)
{
    const char *path = getenv("CT_HANDLER_PROBE_REPORT");
    FILE *report = path ? fopen(path, "w") : NULL;
    if (report) {
        fprintf(report, "%lu %lu %d %d\n", yields, exceptions,
                zend_get_user_opcode_handler(ZEND_YIELD) == probe_yield,
                zend_throw_exception_hook == probe_exception);
        fclose(report);
    }
    zend_set_user_opcode_handler(ZEND_YIELD, preceding_yield);
    zend_throw_exception_hook = preceding_exception;
    return SUCCESS;
}

zend_module_entry handler_chain_probe_module_entry = {
    STANDARD_MODULE_HEADER, "handler_chain_probe", NULL,
    PHP_MINIT(handler_chain_probe), PHP_MSHUTDOWN(handler_chain_probe),
    NULL, NULL, NULL, "1.0", STANDARD_MODULE_PROPERTIES
};
ZEND_GET_MODULE(handler_chain_probe)
