<p align="center">
    <img title="Flutterwave" height="200" src="https://flutterwave.com/images/logo/full.svg" width="50%"/>
</p>

# Flutterwave Magento

## Releases

Download the latest packaged module ZIP from the GitHub Releases page:

- [Releases](https://github.com/OWNER/REPO/releases)

## Introduction

The Magento Plugin makes it very easy and quick to add Flutterwave Payment option on Checkout for your online store. Accept Credit card, Debit card and Bank account payment directly on your store with the Rave payment gateway for WooCommerce.

Available features include:

- Collections: Card, Account, Mobile money, Bank Transfers, USSD, Barter, NQR.
- Recurring payments: Tokenization and Subscriptions (WooCommerce Subscriptions).
- Split payments: Split payments between multiple recipients.


## Requirements

- Magento 2.4+ (or compatible 2.x release) with PHP and composer support
- Access to the Magento CLI (`bin/magento`) and permissions to install modules

## Installation (manual)

1. Copy the module into `app/code/Flutterwave/Payment` (this repo is already structured as the module root).
2. From the Magento project root run:

```bash
php bin/magento module:enable Flutterwave_Payment
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

If you deploy static content or run in production mode, also run `php bin/magento setup:static-content:deploy` as needed.

## Installation (composer)

This repository does not include a `composer.json` package by default. To install via composer, package the module or publish it to your VCS/Central repository and require it with composer, or add a `composer.json` and submit to Packagist.

## Configuration

Configure the module in the Magento Admin:

- Admin: `Stores > Configuration > Sales > Payment Methods > Flutterwave`

Available settings:

- `Enable Flutterwave Payment`: Enable/disable the payment method
- `Title`: The title shown to customers at checkout
- `API Secret Key`: Secret API key used to sign requests
- `API Public Key`: Public API key / identifier
- `Sort Order`: Display order among payment methods
- `Webhook URL`: A label/readonly field that shows the webhook endpoint you should register in the Flutterwave dashboard

## Webhook URL

Register the webhook endpoint at your Flutterwave merchant dashboard. The webhook endpoint for this module is:

```
<magento_base_url>/flutterwave/payment/webhook
```

Replace `<magento_base_url>` with your store's base URL (for example `https://store.example.com/flutterwave/payment/webhook`). The module exposes controllers to handle webhook notifications and payment callbacks.

## Usage

- Once enabled and configured, the Flutterwave payment option will appear at checkout.
- When a payment is created, Flutterwave sends notifications to the webhook endpoint. The module verifies and updates order/payment status accordingly.
- The module also contains callback and redirect controllers used by Flutterwave flows (`Controller/Payment/Callback.php`, `Controller/Payment/Webhook.php`, `Controller/Redirect/Index.php`).

## Developer notes

Important code locations:

- Module registration: `registration.php`
- Module configuration: `etc/module.xml`
- Frontend route: `etc/frontend/routes.xml` (frontName `flutterwave`)
- Admin config form: `etc/adminhtml/system.xml` and block `Block/Adminhtml/System/Config/WebhookUrl.php`
- Gateway commands: `Gateway/Command/InitializeCommand.php`, `Gateway/Command/VerifyCommand.php`
- API client: `Model/Api/Client.php`
- Payment adapter: `Model/Payment/FlutterwaveAdapter.php`
- Payment model: `Model/Payment.php`
- Frontend UI/js: `view/frontend/web/js/view/payment/flutterwave.js` and renderer
- Admin and frontend templates: `view/*/template` and `view/*/layout` files

## Extending or customizing

- To customize request/response handling, modify `Model/Api/Client.php` and `Model/Payment/FlutterwaveAdapter.php`.
- To change checkout behaviors or UI, edit the KnockoutJS view models in `view/frontend/web/js/view/payment/` and the template `view/frontend/web/template/payment/flutterwave.html`.
- Add unit/integration tests alongside the relevant classes where needed.

## Testing & debugging

- Enable developer mode in Magento to see error output: `php bin/magento deploy:mode:set developer`.
- Check `var/log/` and `var/report` for exceptions and debug messages.
- Use `bin/magento setup:di:compile` after changing PHP classes.

## Contributing

Contributions are welcome. Suggested workflow:

1. Fork the repository
2. Create a feature branch
3. Open a pull request describing your changes

When contributing, include tests and update this README where applicable.

## License

This project does not include a license file. Add a `LICENSE` file to clarify licensing (MIT, BSD, etc.) before publishing.

## Contact

For questions or help integrating Flutterwave Payment, open an issue in this repository or reach out to the maintainer.

---

Generated from repository structure on November 14, 2026.