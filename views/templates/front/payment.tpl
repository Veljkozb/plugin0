{*
  Stranica front controller-a "payment": izabrana metoda plaćanja iz plugina, dok Worldline korak ne stigne.
  Kontroler šalje: method_name, method_code, type_label, type_description, total (formatiran iznos), back_url.
*}
{extends file='page.tpl'}

{block name='page_title'}{$method_name}{/block}

{block name='page_content'}
  <section id="plugin0-payment">
    <p><strong>{$type_label}</strong>: {$type_description}</p>
    <p>{l s='Order total' d='Modules.Plugin0.Shop'}: {$total}</p>
    <p>{l s='The payment itself is not connected yet. This step will create the Worldline session and take you to the payment page.' d='Modules.Plugin0.Shop'}</p>
    <a class="btn btn-secondary" href="{$back_url}">{l s='Back to checkout' d='Modules.Plugin0.Shop'}</a>
  </section>
{/block}
