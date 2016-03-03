<?php

class AdminSecurionPayController extends ModuleAdminController
{

    public function __construct()
    {
        $this->lang = true;

        parent::__construct();
    }

    /**
     * @return string
     */
    public function renderList()
    {
        return $this->getContent();
    }

    /**
     * @return string
     */
    public function getContent()
    {
        $paymentsParams = array();

        $output = '<h2>'
                . 'SecurionPay '
                . 'refund for order id: '
                . Tools::getValue('id_order')
                . '</h2>';

        $order = new Order(Tools::getValue('id_order'));
        $payments = $order->getOrderPaymentCollection();

        $currency = new Currency((int) $order->id_currency);

        foreach ($payments as $payment) {
            if ($payment->payment_method == 'Card payment') {
                $paymentsParams[] = array(
                    'id' => $payment->transaction_id,
                    'name' => $this->l($payment->transaction_id
                            . ' ' . $payment->amount
                            . ' ' . $currency->sign),
                );
            }
        }

        return $output . $this->renderForm($paymentsParams);
    }

    /**
     * @param array $paymentsParams
     */
    public function renderForm($paymentsParams)
    {
        $this->show_toolbar = false;
        $this->fields_form = array(
            'input' => array(
                array(
                    'type' => 'select',
                    'label' => $this->l('Choose payment to refund'),
                    'name' => 'payment',
                    'options' => array(
                        'query' => $paymentsParams,
                        'id' => 'id',
                        'name' => 'name'
                    ),
                    'required' => true
                ),
                array(
                    'type' => 'hidden',
                    'label' => $this->l('Order id:'),
                    'name' => 'id_order',
                    'readonly' => true,
                    'disabled' => true,
                    'size' => 40,
                ),
            ),
            'submit' => array(
                'title' => $this->l('Refund'),
                'class' => 'button'
            )
        );

        return parent::renderForm();
    }

    public function postProcess()
    {
        if (Tools::isSubmit('submitAdd' . $this->table)) {

            $orderPayment = $this->getOrderPaymentByTransactionId(Tools::getValue('payment'));

            $order = new Order((int)Tools::getValue('id_order'));

            if (!Validate::isLoadedObject($order)) {
                $this->errors[] = Tools::displayError($this->l('An error has occurred: Order object is not valid'));
            }

            $refundObject = $this->createRefundRequest($orderPayment);

            if (method_exists($refundObject, "getRefunded") && $refundObject->getRefunded() == true) {

                $order->setCurrentState(7); // Refund state

                if ($order->save()) {
                    Tools::redirectAdmin('index.php?controller=AdminOrders&id_order='
                            . Tools::getValue('id_order')
                            . '&vieworder&token='
                            . Tools::getAdminTokenLite('AdminOrders'));
                } else {
                    $this->errors[] = Tools::displayError($this->l('An error has occurred: Can\'t save current state form order'));
                }

            } else {
                $this->errors[] = Tools::displayError($this->l('An error has occurred: Can\'t refund this payment'));
            }
        }
    }

    /**
     * @param OrderPayment $orderPayment
     * @return \SecurionPay\Response\Charge
     */
    protected function createRefundRequest($orderPayment)
    {
        $currencyObject = new Currency((int) $orderPayment['id_currency']);
        $currency = $currencyObject->iso_code;

        $total = (float) $orderPayment['amount'];
        $amount = CurrencyUtils::toMinorUnits($total, $currency);

        try {
            $refundRequest = new \SecurionPay\Request\RefundRequest();
            $refundRequest->amount($amount)
                ->chargeId($orderPayment['transaction_id']);

            return $this->module->gateway->refundCharge($refundRequest);

        } catch (Exception $e) {
            $this->errors[] = Tools::displayError('Refund exception: ' . $e->getMessage());
        }
    }

    /**
     * @param string $transactionId
     * @return OrderPayment
     */
    protected function getOrderPaymentByTransactionId($transactionId)
    {
        $orderPayment = Db::getInstance()
                ->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'order_payment` '
                . 'WHERE transaction_id = "' . pSQL($transactionId) . '" '
                . 'AND payment_method = "Card payment" LIMIT 1');

        if (!$orderPayment) {
            $this->errors[] = Tools::displayError($this->l('Payment not found'));
        } elseif (is_array($orderPayment) && isset($orderPayment[0])) {
            $orderPayment = $orderPayment[0];
        }

        return $orderPayment;
    }

}
