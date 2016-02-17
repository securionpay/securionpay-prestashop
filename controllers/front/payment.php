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

        /* @var $gateway \SecurionPay\SecurionPayGateway */
        $gateway = $this->module->createGateway();
        $charge = $gateway->retrieveCharge($chargeId);

        if (!$charge) {
            throw new \UnexpectedValueException($this->module->l('Charge is not set', 'payment'));
        }

        $metadata = $charge->getMetadata();
        if (!$metadata) {
            $metadata = [];
        }

        // ensure that charge is not assigned to another order
        if (isset($metadata[self::METADATA_ORDER_ID])) {
            throw new \UnexpectedValueException($this->module->l('This payment is already assiged to some order', 'payment'));
        }

        // Check that this payment option is still available 
        // in case the customer changed his address just before 
        // the end of the checkout process
        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'securionpay') {
                $authorized = true;
                break;
            }
        }
        
        if (!$authorized) {
            throw new \RuntimeException($this->module->l('This payment method is not available.', 'payment'));
        }

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $currency = $this->context->currency;
        $total = $this->module->fromMinorUnits($charge->getAmount(), $charge->getCurrency());
        $extraVars = [
            'transaction_id' => $charge->getId()
        ];
        
        $paymentMethod = $this->module->l('Card payment', 'payment');
        if (!$paymentMethod) {
            $paymentMethod = $this->module->displayName;
        }

        // confirm order and mark it as paid
        $this->module->validateOrder(
                $cart->id, Configuration::get('PS_OS_PAYMENT'), $total, $paymentMethod, null, $extraVars, (int) $currency->id, false, $customer->secure_key
        );

        // update change metadata
        $metadata[self::METADATA_ORDER_ID] = $this->module->currentOrder;
        $metadata[self::METADATA_ORDER_REFERENCE] = $this->module->currentOrderReference;

        $chargeUpdate = new \SecurionPay\Request\ChargeUpdateRequest();
        $chargeUpdate->chargeId($chargeId)->metadata($metadata);
        $gateway->updateCharge($chargeUpdate);

        // update payment details
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
        }

        // redirect to order-confirmation page
        Tools::redirect('index.php?controller=order-confirmation' .
                '&id_cart=' . $cart->id . 
                '&id_order=' . $this->module->currentOrder .
                '&key=' . $customer->secure_key
        );
    }

}
