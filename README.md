# QUIQQER Currencies

![QUIQQER Currencies](bin/images/Readme.png)

The Currencies module adds multi-currency support to QUIQQER. It manages currencies, exchange rates and locale-aware amount formatting and can update rates automatically from the European Central Bank (ECB).

## Features

- Manage currencies and their exchange rates
- Convert and format amounts in different currencies
- Configure default, accounting and user currencies
- Restrict the currencies available to users
- Update exchange rates automatically from the ECB
- Extend the built-in currency type through package providers

## Installation

Install the package through the QUIQQER package manager or Composer:

```shell
composer require quiqqer/currency
```

Run the QUIQQER setup after installation so that package metadata, permissions, settings and the database schema are imported.

## Configuration

Open the QUIQQER administration settings and select **ERP → Currencies**. There you can choose the default and accounting currencies, manage allowed currencies and trigger the ECB import.

The package also registers the `currency:import` console command and an hourly cron job for automatic rate updates.

## Development

Initialize the package-local tools and run all checks:

```shell
composer dev:init
composer test
```

The source code is available in the [QUIQQER GitLab project](https://dev.quiqqer.com/quiqqer/currency). Please report defects in the [issue tracker](https://dev.quiqqer.com/quiqqer/currency/-/issues).

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).

## Support

For support, contact [support@pcsg.de](mailto:support@pcsg.de) or visit the [QUIQQER community](https://community.quiqqer.com).
