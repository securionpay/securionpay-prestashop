<?php

if (!defined('_PS_VERSION_')) {
    exit;
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
    const CHECKOUT_PAYMENT_BUTTON = 'SECURIONPAY_CHECKOUT_PAYMENT_BTN';

    public function __construct()
    {
        $this->name = 'securionpay';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.1';
        $this->author = 'SecurionPay';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array(
            'min' => '1.5',
            'max' => _PS_VERSION_
        );
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('SecurionPay Payment Gateway');
        $this->description = $this->l('Enables you to process Credit and Debit Cards payments');

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');

        $this->gateway = $this->createGateway();
    }

    /**
     * @return boolean
     */
    public function install()
    {
        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        // Install invisible tab
        $tab = new Tab();
        $tab->name[$this->context->language->id] = $this->l('Securionpay');
        $tab->class_name = 'AdminSecurionpay';
        $tab->id_parent = -1; // No parent tab
        $tab->module = $this->name;
        $tab->add();

        //Init
        Configuration::updateValue('SECURIONPAY_CONF', '');

        if (!parent::install()) {
            return false;
        }

        if (!Configuration::updateValue(self::MODE, self::MODE_TEST)) {
            return false;
        }

        if (!$this->registerHook('payment') ||
            !$this->registerHook('displayAdminOrder')) {

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
     * @param array $params
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
        $amount = Utilities::toMinorUnits($total, $currency);

        $signedCheckoutRequest = $this->createSecurionPayCharge($amount, $currency);

        $this->context->smarty->assign(array(
            'publickKey' => $this->getPublicKey(),
            'email' => $this->getCurrentUserEmail(),
            'checkoutFirstLine' => Configuration::get(self::CHECKOUT_FIRST_LINE),
            'checkoutSecondLine' => Configuration::get(self::CHECKOUT_SECOND_LINE),
            'checkoutPaymentButton' => Configuration::get(self::CHECKOUT_PAYMENT_BUTTON),
            'checkoutRequest' => $signedCheckoutRequest,
            'version' => (float) _PS_VERSION_
        ));

        if ((float) _PS_VERSION_ >= '1.6') {
            $this->context->controller->addCSS(_MODULE_DIR_ . $this->name . '/views/css/securionpay.css');
        }
        
        return $this->display(__FILE__, 'payment.tpl');
    }

    /**
     * @param array $hook
     * @return string
     */
    public function hookdisplayAdminOrder($hook)
    {
        $order = new Order(Tools::getValue('id_order'));

        $securionPayPayment = null;

        foreach ($order->getOrderPaymentCollection() as $payment) {
            if ($payment->payment_method == 'Card payment' &&
                $order->module == 'securionpay' && $order->getCurrentState() != 7) {

                $securionPayPayment = $payment;
                break;
            }
        }

        if (!$securionPayPayment) {
            return '';
        }

        $this->context->smarty->assign(array(
            'button_href' =>  'index.php?controller=AdminSecurionpay' .
                '&id_order=' . Tools::getValue('id_order') .
                '&token=' . Tools::getAdminTokenLite('AdminSecurionpay'),
            'version' => (float) _PS_VERSION_
        ));

        return $this->display(__FILE__, 'displayAdminOrder.tpl');
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
        // Init Fields form array
        $fieldsForm[0]['form'] = array(
            'legend' => array(
                'title' => $this->l('Settings')
            ),
            'input' => $this->getConfigurationFields(),
            'submit' => array(
                'title' => $this->l('Save'),
                'class' => 'button'
            )
        );

        $helper = $this->getConfiguredHelperForm();

        return $helper->generateForm($fieldsForm);
    }

    /**
     * @return HelperForm
     */
    protected function getConfiguredHelperForm()
    {
        $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');

        $helper = new HelperForm();

        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;

        // Language
        $helper->default_form_language = $defaultLang;
        $helper->allow_employee_form_lang = $defaultLang;

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

        return $helper;
    }

    /**
     * @return \SecurionPay\SecurionPayGateway
     */
    protected function createGateway()
    {
        require_once __DIR__ . '/lib/SecurionPay/Util/SecurionPayAutoloader.php';
        \SecurionPay\Util\SecurionPayAutoloader::register();
        require_once __DIR__ . '/PrestashopCurlConnection.php';
        require_once __DIR__ . '/Utilities.php';

        return new \SecurionPay\SecurionPayGateway($this->getPrivateKey(), new PrestashopCurlConnection($this->version));
    }

    /**
     * @return string|null
     */
    protected function getCurrentUserEmail()
    {
        if ($this->context->customer && $this->context->customer->email) {
            return $this->context->customer->email;
        }

        return null;
    }

    /**
     * @return array
     */
    protected function getConfigurationFields()
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
     * @param integer $amount
     * @param string $currency
     * @return string
     */
    protected function createSecurionPayCharge($amount, $currency)
    {
        $chargeRequest = new \SecurionPay\Request\CheckoutRequestCharge();
        $chargeRequest->amount($amount)->currency($currency);

        $checkoutRequest = new \SecurionPay\Request\CheckoutRequest();
        $checkoutRequest->charge($chargeRequest);

        return $this->gateway->signCheckoutRequest($checkoutRequest);
    }

    /**
     * @return string
     */
    protected function getPrivateKey()
    {
        return $this->isLiveMode() ? Configuration::get(self::LIVE_PRIVATE_KEY) : Configuration::get(self::TEST_PRIVATE_KEY);
    }

    /**
     * @return string
     */
    protected function getPublicKey()
    {
        return $this->isLiveMode() ? Configuration::get(self::LIVE_PUBLIC_KEY) : Configuration::get(self::TEST_PUBLIC_KEY);
    }

    /**
     * @return string
     */
    protected function isLiveMode()
    {
        return Configuration::get(self::MODE) == self::MODE_LIVE;
    }

}
