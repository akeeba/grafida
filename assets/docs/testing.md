# Automated testing

```bash
composer test            # unit + feature + integration suites
composer linter:check    # PHPStan static analysis
```

The back-end is a pure `Request → Response` function, so the unit and feature suites run
without opening a window.
