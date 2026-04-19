PHP_ARG_ENABLE(codetracer, whether to enable CodeTracer support,
  [  --enable-codetracer     Enable CodeTracer tracing extension])

if test "$PHP_CODETRACER" != "no"; then
  PHP_NEW_EXTENSION(codetracer, codetracer_php.c, $ext_shared)
fi
