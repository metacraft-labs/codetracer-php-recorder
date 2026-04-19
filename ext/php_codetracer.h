#ifndef PHP_CODETRACER_H
#define PHP_CODETRACER_H

#define PHP_CODETRACER_VERSION "0.1.0"
#define PHP_CODETRACER_EXTNAME "codetracer"

extern zend_module_entry codetracer_module_entry;
#define phpext_codetracer_ptr &codetracer_module_entry

#endif
