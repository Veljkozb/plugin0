{*
  Stranica front controller-a "return" kad je plaćanje i posle čekanja u obradi, a narudžbina još ne postoji.
  Narudžbinu kasnije pravi Core kad sazna konačan status (webhook ili provera u pozadini; nijedno još nije povezano).
  Kontroler šalje: home_url.
*}
{extends file='page.tpl'}

{block name='page_title'}{l s='Payment is being processed' d='Modules.Plugin0.Shop'}{/block}

{block name='page_content'}
  <section id="plugin0-return">
    <p>{l s='We have not received the final result of your payment from Worldline yet.' d='Modules.Plugin0.Shop'}</p>
    <p>{l s='Your order will be created as soon as the payment is confirmed. Please do not pay again for the same cart.' d='Modules.Plugin0.Shop'}</p>
    <a class="btn btn-secondary" href="{$home_url}">{l s='Back to the shop' d='Modules.Plugin0.Shop'}</a>
  </section>
{/block}
