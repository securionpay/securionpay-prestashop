{if $errors|@count > 0}
    <div class="error">
        <ul>
            {foreach from=$errors item=error}
                <li>{$error}</li>
            {/foreach}
        </ul>
    </div>
{/if}

{if $version < 1.6 }
    <br>
    <fieldset>
        <legend><img src="/../modules/securionpay/views/img/logo.png" width="15"/> {l s='Return payments by SecurionPay' mod='securionpay'}</legend>
        <span style="font-weight: bold; font-size: 14px;">
            <a class="securionpay_link" data-href="{$button_href}" title="{l s='Return payments by SecurionPay' mod='securionpay'}" style="color:black;">
                {l s='Return payments' mod='securionpay'}
            </a>
        </span>
    </fieldset>
{else}
    <div class="well hidden-print">
        <div class="row">
            <div class="col-xs-12">
                <div class="btn btn-danger">
                    <a class="securionpay_link" data-href="{$button_href}" title="{l s='Return payments by SecurionPay' mod='securionpay'}" style="color:white;">
                        {l s='Return payments by SecurionPay' mod='securionpay'}
                    </a>
                </div>
            </div>
        </div>
    </div>
{/if}
<script type="text/javascript">
    $(document).ready(function () {
        $(".securionpay_link").on("click", function () {
            var linkPage = $(this).data('href');
            window.location.href = linkPage;
        });
    });
</script>