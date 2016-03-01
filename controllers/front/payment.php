<?php

class SecurionPayPaymentModuleFrontController extends ModuleFrontController
{
    const METADATA_ORDER_ID = 'prestashop-order-id';
    const METADATA_ORDER_REFERENCE = 'prestashop-order-reference';

    /**
     * @return Redirect
     */
    public function postProcess()
    {
        /* @var $cart CartCore */
        $cart = $this->context->cart;
        $chargeId = Tools::getValue('changeId');

        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 ||
                !$this->module->active || !$chargeId) {

            Tools::redirect('index.php?controller=order&step=1');
        }

        /* @var $this->module->gateway \SecurionPay\SecurionPayGateway */
        $charge = $this->module->gateway->retrieveCharge($chargeId);

        if (!$charge) {
            throw new \UnexpectedValueException($this->module->l('Charge is not set', 'payment'));
        }

        $metadata = $this->resolveMetadata($charge);

        // Check that this payment option is still available
        // in case the customer changed his address just before
        // the end of the checkout process
        $this->validatePaymentOption();

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $this->validateOrder($charge, $customer->secure_key, $cart->id);

        // update metadata
        $metadata[self::METADATA_ORDER_ID] = $this->module->currentOrder;
        $metadata[self::METADATA_ORDER_REFERENCE] = $this->module->currentOrderReference;

        $chargeUpdate = new \SecurionPay\Request\ChargeUpdateRequest();
        $chargeUpdate->chargeId($chargeId)->metadata($metadata);
        $this->module->gateway->updateCharge($chargeUpdate);

        $this->updatePaymentDetails($charge);

        // redirect to order-confirmation page
        Tools::redirect('index.php?controller=order-confirmation' .
                '&id_cart=' . $cart->id .
                '&id_order=' . $this->module->currentOrder .
                '&key=' . $customer->secure_key
        );
    }

    /**
     * @param SecurionPay Charge $charge
     * @throws \RuntimeException
     */
    protected function updatePaymentDetails($charge)
    {
        $order = new Order((int) $this->module->currentOrder);
        if (Validate::isLoadedObject($order)) {
            $payments = $order->getOrderPaymentCollection();
            foreach ($payments as $payment) {
                if ($payment->transaction_id == $charge->getId()) {
                    $card = $charge->getCard();
                    $payment->card_number = '************' . $card->getLast4();
                    $payment->card_brand = $card->getBrand();
                    $payment->card_expiration = $card->getExpMonth() . '/' . $card->getExpYear();
                    $payment->card_holder = $card->getCardholderName();
                    $payment->save();
                }
            }
        } else {
            throw new \RuntimeException($this->module->l('It is not valid order object!', 'payment'));
        }
    }

    /**
     * @param \SecurionPay\Response\Charge $charge
     * @return array
     */
    protected function resolveMetadata($charge)
    {
        $metadata = $charge->getMetadata();
        if (!$metadata) {
            $metadata = array();
        }

        // ensure that charge is not assigned to another order
        if (isset($metadata[self::METADATA_ORDER_ID])) {
            throw new \UnexpectedValueException($this->module->l('This payment is already assigned to some order', 'payment'));
        }

        return $metadata;
    }

    /**
     * @param \SecurionPay\Response\Charge $charge
     * @param string $customerSecureKey
     * @param integer $cartId
     */
    protected function validateOrder($charge, $customerSecureKey, $cartId)
    {
        $currency = $this->context->currency;
        $total = CurrencyUtils::fromMinorUnits($charge->getAmount(), $charge->getCurrency());
        $extraVars = array(
            'transaction_id' => $charge->getId()
        );

        $paymentMethod = $this->module->l('Card payment', 'payment');

        // confirm order and mark it as paid
        $this->module->validateOrder(
            $cartId, Configuration::get('PS_OS_PAYMENT'), $total, $paymentMethod, null, $extraVars, (int) $currency->id, false, $customerSecureKey
        );
    }

    /**
     * @return boolean
     * @throws \RuntimeException
     */
    protected function validatePaymentOption()
    {
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'securionpay') {
                return true;
            }
        }

        throw new \RuntimeException($this->module->l('This payment method is not still available.', 'payment'));
    }

}
