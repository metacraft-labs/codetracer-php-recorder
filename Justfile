# Run tests
test:
    php tests/test_span.php

# Run a test PHP script with span tracking
demo:
    php -d auto_prepend_file=src/auto_prepend.php -S localhost:8095 -t test-programs/
