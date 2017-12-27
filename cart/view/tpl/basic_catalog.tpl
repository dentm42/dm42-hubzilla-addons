<div class="dm42cart catalog">
  <div class='section-title-wrapper'>
    <div class="title">{{if $title}}{{$title}}{{else}}Catalog{{/if}}</div>
  </div>
  <div class='section-content-wrapper'>
    <form method="post">
    <input type="hidden" name="cart_posthook" value="add_item">
    <table>
        <tr>
            <th></th>
            <th>Description</th>
            <th>Price each {{if $currencysymbol}}({{$currencysymbol}}){{/if}}</th>
        </tr>
    {{foreach $items as $item}}
        <tr>
            <td><button class="btn btn-primary" type="submit" name="add" id="newchannel-submit-button" value="{{$item.item_sku}}">Add</button></td>
            <td>{{$item.item_desc}}</td>
            <td>{{$item.item_price}}</td>
        </tr>
    {{/foreach}}
    </table>
    </form>
  </div>
</div>
