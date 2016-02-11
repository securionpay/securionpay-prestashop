<?php

if (!defined('_PS_VERSION_')) {
    exit();
}

class Securionpay extends PaymentModule
{

    const MODE = 'SECURIONPAY_MODE';
    const MODE_TEST = 'test';
    const MODE_LIVE = 'live';
    const TEST_PRIVATE_KEY = 'SECURIONPAY_TEST_PRIVATE_KEY';
    const TEST_PUBLIC_KEY = 'SECURIONPAY_TEST_PUBLIC_KEY';
    const LIVE_PRIVATE_KEY = 'SECURIONPAY_LIVE_PRIVATE_KEY';
    const LIVE_PUBLIC_KEY = 'SECURIONPAY_LIVE_PUBLIC_KEY';
    const CHECKOUT_FIRST_LINE = 'SECURIONPAY_CHECKOUT_FIRST_LINE';
    const CHECKOUT_SECOND_LINE = 'SECURIONPAY_CHECKOUT_SECOND_LINE';
    const CHECKOUT_PAYMENT_BUTTON = 'SECURIONPAY_CHECKOUT_PAYMENT_BUTTON';

    public function __construct()
    {
        $this->name = 'securionpay';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'SecurionPay';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array(
            'min' => '1.6',
            'max' => _PS_VERSION_
        );
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('SecurionPay Payment Gateway');
        $this->description = $this->l('Enables you to process Credit and Debit Cards payments');

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');

        if (!Configuration::get('MYMODULE_NAME')) {
            $this->warning = $this->l('No name provided');
        }
    }

    /**
     * @return boolean
     */
    public function install()
    {
        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        if (!parent::install()) {
            return false;
        }

        if (!Configuration::updateValue(self::MODE, self::MODE_TEST)) {
            return false;
        }

        if (!$this->registerHook('payment')) {
            return false;
        }

        return true;
    }

    /**
     * @return boolean
     */
    public function uninstall()
    {
        if (!parent::uninstall()) {
            return false;
        }

        foreach ($this->getConfigurationFields() as $field) {
            if (!Configuration::deleteByName($field['name'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array $params !UNUSED
     * @return Response
     */
    public function hookPayment($params)
    {
        if (!$this->active) {
            return;
        }

        $cart = $this->context->cart;

        $currencyObject = new Currency((int) $cart->id_currency);
        $currency = $currencyObject->iso_code;

        $total = (float) $cart->getOrderTotal(true, Cart::BOTH);
        $amount = $this->toMinorUnits($total, $currency);

        $gateway = $this->createGateway();

        $chargeRequest = new \SecurionPay\Request\CheckoutRequestCharge();
        $chargeRequest->amount($amount)->currency($currency);

        $checkoutRequest = new \SecurionPay\Request\CheckoutRequest();
        $checkoutRequest->charge($chargeRequest);

        $signedCheckoutRequest = $gateway->signCheckoutRequest($checkoutRequest);

        $this->context->smarty->assign(array(
            'publickKey' => $this->getPublicKey(),
            'email' => $this->getCurrentUserEmail(),
            'checkoutFirstLine' => Configuration::get(self::CHECKOUT_FIRST_LINE),
            'checkoutSecondLine' => Configuration::get(self::CHECKOUT_SECOND_LINE),
            'checkoutPaymentButton' => Configuration::get(self::CHECKOUT_PAYMENT_BUTTON),
            'checkoutRequest' => $signedCheckoutRequest
        ));
        $this->context->controller->addCSS(_MODULE_DIR_ . $this->name . '/views/css/securionpay.css');

        return $this->display(__FILE__, 'payment.tpl');
    }

    /**
     * @return string
     */
    public function getContent()
    {
        $output = null;

        if (Tools::isSubmit('submit' . $this->name)) {
            foreach ($this->getConfigurationFields() as $field) {
                $value = trim(strval(Tools::getValue($field['name'])));
                Configuration::updateValue($field['name'], $value);
            }
        }

        $output .= $this->displayConfirmation($this->l('Settings updated'));

        return $output . $this->displayForm();
    }

    /**
     * @return string
     */
    public function displayForm()
    {
        // Get default language
        $default_lang = (int) Configuration::get('PS_LANG_DEFAULT');

        // Init Fields form array
        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => $this->l('Settings')
            ),
            'input' => $this->getConfigurationFields(),
            'submit' => array(
                'title' => $this->l('Save'),
                'class' => 'button'
            )
        );

        $helper = new HelperForm();

        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;

        // Language
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;

        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->show_toolbar = true; // false -> remove toolbar
        $helper->toolbar_scroll = true; // yes - > Toolbar is always visible on the top of the screen.
        $helper->submit_action = 'submit' . $this->name;
        $helper->toolbar_btn = array(
            'save' => array(
                'desc' => $this->l('Save'),
                'href' => AdminController::$currentIndex . '&configure=' . $this->name . '&save' . $this->name .
                '&token=' . Tools::getAdminTokenLite('AdminModules')
            ),
            'back' => array(
                'href' => AdminController::$currentIndex . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Back to list')
            )
        );

        // Load current value
        foreach ($this->getConfigurationFields() as $field) {
            $helper->fields_value[$field['name']] = Configuration::get($field['name']);
        }

        return $helper->generateForm($fields_form);
    }

    /**
     * @param mixed $amount
     * @param string $currency
     * @return integer
     */
    public function toMinorUnits($amount, $currency)
    {
        return $this->roundToInt($amount * $this->getMinorUnitsFactor($currency)); // todo, better functions
    }

    /**
     * @param integer $amountInMinorUnits
     * @param string $currency
     * @return float
     */
    public function fromMinorUnits($amountInMinorUnits, $currency)
    {
        return (float) ($amountInMinorUnits / $this->getMinorUnitsFactor($currency));
    }

    /**
     * @return \SecurionPay\SecurionPayGateway
     */
    public function createGateway()
    {
        require_once __DIR__ . '/lib/SecurionPay/Util/SecurionPayAutoloader.php';
        \SecurionPay\Util\SecurionPayAutoloader::register();

        require_once __DIR__ . '/connection.php';

        return new \SecurionPay\SecurionPayGateway($this->getPrivateKey(), new PrestashopCurlConnection($this->version));
    }

    /**
     * @return string|null
     */
    private function getCurrentUserEmail()
    {
        if ($this->context->customer && $this->context->customer->email) {
            return $this->context->customer->email;
        } else {
            return null;
        }
    }

    /**
     * @param mixed $value
     * @return integer
     */
    private function roundToInt($value)
    {
        return (int) round($value, 0, PHP_ROUND_HALF_UP);
    }

    /**
     * @return array
     */
    private function getConfigurationFields()
    {
        return array(
            array(
                'type' => 'select',
                'label' => $this->l('Mode'),
                'desc' => $this->l(
                        'Use the test mode to verify everything works before going live.'
                ),
                'name' => self::MODE,
                'options' => array(
                    'query' => array(
                        array(
                            'id' => self::MODE_TEST,
                            'name' => $this->l('Test mode')
                        ),
                        array(
                            'id' => self::MODE_LIVE,
                            'name' => $this->l('Live mode')
                        )
                    ),
                    'id' => 'id',
                    'name' => 'name'
                ),
                'required' => true
            ),
            array(
                'type' => 'text',
                'label' => $this->l('API Test Secret Key'),
                'name' => self::TEST_PRIVATE_KEY,
                'size' => 32
            ),
            array(
                'type' => 'text',
                'label' => $this->l('API Test Public Key'),
                'name' => self::TEST_PUBLIC_KEY,
                'size' => 32
            ),
            array(
                'type' => 'text',
                'label' => $this->l('API Live Secret Key'),
                'name' => self::LIVE_PRIVATE_KEY,
                'size' => 32
            ),
            array(
                'type' => 'text',
                'label' => $this->l('API Live Public Key'),
                'name' => self::LIVE_PUBLIC_KEY,
                'size' => 32
            ),
            array(
                'type' => 'text',
                'label' => $this->l('Checkout first line text'),
                'name' => self::CHECKOUT_FIRST_LINE,
                'size' => 32
            ),
            array(
                'type' => 'text',
                'label' => $this->l('Checkout second line text'),
                'name' => self::CHECKOUT_SECOND_LINE,
                'size' => 32
            ),
            array(
                'type' => 'text',
                'label' => $this->l('Checkout payment button'),
                'desc' => $this->l(
                        'If text contains {{amount}} then it will be replaced by charge amount. Otherwise charge amount will be appended at the end.'
                ),
                'name' => self::CHECKOUT_PAYMENT_BUTTON,
                'size' => 32
            )
        );
    }

    /**
     * @return string
     */
    private function getPrivateKey()
    {
        return $this->isLiveMode() ? Configuration::get(self::LIVE_PRIVATE_KEY) : Configuration::get(self::TEST_PRIVATE_KEY);
    }

    /**
     * @return string
     */
    private function getPublicKey()
    {
        return $this->isLiveMode() ? Configuration::get(self::LIVE_PUBLIC_KEY) : Configuration::get(self::TEST_PUBLIC_KEY);
    }

    /**
     * @return string
     */
    private function isLiveMode()
    {
        return Configuration::get(self::MODE) == self::MODE_LIVE;
    }

    /**
     * @param string $currency
     * @return float
     */
    private function getMinorUnitsFactor($currency)
    {
        $minorUnitsLookup = array(
            'BHD' => 3, 'BYR' => 0, 'BIF' => 0, 'CLF' => 0, 'CLP' => 0, 'KMF' => 0, 'DJF' => 0, 'XAF' => 0, 'GNF' => 0,
            'ISK' => 0, 'IQD' => 3, 'JPY' => 0, 'JOD' => 3, 'KRW' => 0, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'PYG' => 0,
            'RWF' => 0, 'XOF' => 0, 'TND' => 3, 'UYI' => 0, 'VUV' => 0, 'VND' => 0, 'XPF' => 0
        );
        $minorUnits = isset($minorUnitsLookup[$currency]) ? $minorUnitsLookup[$currency] : 2;

        return (int) bcpow("$minorUnits", "10", 0);
    }

}
