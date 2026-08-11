# ReadyData Magento modules

Magento 2 modules that make a store work with [ReadyData](https://github.com/scandiweb/readydata).
The repository root maps to `app/code/ReadyData/`, so each directory below is one
module and can be developed in place inside a Magento checkout.

| Module | Directory | Purpose |
|---|---|---|
| `ReadyData_Import` | [`Import/`](Import/) | Bulk product, attribute and category import via REST, writing directly to the database. See [Import/README.md](Import/README.md). |

## Installation

```
composer require readydata/magento-modules
bin/magento module:enable ReadyData_Import
bin/magento setup:upgrade
bin/magento setup:di:compile   # on a compiled (production) deployment
```

For local development, clone the repository straight into the Magento checkout
so edits are live:

```
git clone git@github.com:scandiweb/readydata-magento.git app/code/ReadyData
bin/magento module:enable ReadyData_Import
bin/magento setup:upgrade
```

## Layout

One Composer package registers every module in the repository — `autoload.files`
lists each module's `registration.php` and `autoload.psr-4` maps each namespace
to its directory. Adding a module means adding a directory plus two lines in
[composer.json](composer.json); it does not mean a new package to publish or
require.

The package was previously named `readydata/module-import` and installed the
import module at the repository root. That name is kept as a `replace` entry so
an existing `composer require readydata/module-import` resolves here rather than
installing a second, conflicting copy.
