<html>
  <head>
    <title>PS PHP Library Params Test</title>
    <?php require 'ps-v3-library.php' ?>
  </head>
  <body>
    <h3>
      <center>
        Simple test of PopShops V3 API PHP Library
      </center>
    </h3>
    <hr>
    <h4>
      Logging information (off by default):
    </h4>
    <?php
      $api = new PsApiCall(array('account' => 'd1lg0my9c6y3j5iv5vkc6ayrd', 'catalog' => 'dp4rtmme6tbhugpv6i59yiqmr', 'logging' => true, 
				 'url-mode-prefix' => 'popshops-api-', 'url-mode' => true));
      $api->get('products', array('keyword' => 'wallet'));
    ?>
    <hr>
    <h4>
      Demo of library deal capabilities (look at the PHP source to see what is going on):
    </h4>
    <?php
    foreach ($api->resource('deal_types') as $deal_type) {
      print('DEAL TYPE: ' . $deal_type->attr('name') . '<br>');
      foreach ($deal_type->resource('deals') as $deal) {
	print('==DEAL: ' . $deal->attr('name') . ' FROM ' . $deal->resource('merchant')->attr('name') . '<br>');
	foreach ($deal->resource('deal_types') as $deal_type_2) {
	  print('====MY DEAL TYPE: ' . $deal_type_2->attr('name') . '<br>');
	}
      }
    }
    ?>
    <br><hr><br>
    <h4>
      Demo of library category capabilities (look at the PHP source to see what is going on):
    </h4>
    <?php
      foreach ($api->resource('categories') as $category) {
	print('=CATEGORY: ' . $category->attr('name') . ' -- ' . $category->attr('id') . '<br>');
	foreach ($category->resource('products') as $product) {
	  print('==PRODUCT: ' . $product->attr('name') . '<br>');
	  foreach($product->resource('offers') as $offer) {
	    print('===OFFER: ' . $offer->attr('name') . '<br>');
	    print('====MERCHANT: ' . $offer->resource('merchant')->attr('name') . '<br>');
	  }
	}
      }
    ?>
  </body>
</html>
