# Stand-in WordPress and WooCommerce source for `bin/compat-api.php`

A minimal tree in the layout `bin/compat-api.php --wp=<folder>` reads (`wp-includes/version.php`,
`wp-content/plugins/woocommerce/woocommerce.php`, the Action Scheduler package). Each file declares only the
symbols the mini plugins in `../violations` and `../clean` use, with `@since` (and `@deprecated`) tags taken from
the real WordPress 7.1.4 / WooCommerce 11.3.0 source of the LGA test site (DESIGN.md §12.1). The bodies are empty:
nothing here is loaded, it is only tokenized. `tests/Unit/Wiring/CompatScansTest.php` runs the scan against this
tree on every unit run, and against the real site source when `LGA_WP_PATH` is set.
