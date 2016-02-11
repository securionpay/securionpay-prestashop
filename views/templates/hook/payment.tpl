<div class="row">
    <div class="col-xs-12">
        <p class="payment_module">
            <a id="securionpay-button" class="securionpay" href="#" title="{l s='Pay by card' mod='securionpay'}"> 
                {l s='Pay by card' mod='securionpay'}
            </a>
        </p>
    </div>
</div>

<form id="securionpay-form" action="{$link->getModuleLink('securionpay', 'payment')|escape:'html'}" method="post">
    <input id="securionpay-charge" type="hidden" name="changeId" value="">
</form>

<script src="https://securionpay.com/checkout.js"></script>
<script type="text/javascript">
    SecurionpayCheckout.key = '{$publickKey}';

    SecurionpayCheckout.success = function (result) {ldelim}
        jQuery('#securionpay-charge').val(result.charge.id);
        jQuery('#securionpay-form').submit();
    {rdelim};

    SecurionpayCheckout.error = function (errorMessage) {ldelim}
        if (console && console.log) {
            console.log(errorMessage);
        }
    {rdelim};

    jQuery('#securionpay-button').click(function () {ldelim}
        SecurionpayCheckout.open({ldelim}
            {if $email}
                email : '{$email}',
            {/if}
                name : '{$checkoutFirstLine}',
                description : '{$checkoutSecondLine}',
            {if $checkoutPaymentButton}
                paymentButton : '{$checkoutPaymentButton}',
            {/if}
            checkoutRequest : '{$checkoutRequest}'
    {rdelim});
        return false;
    {rdelim});
</script>
