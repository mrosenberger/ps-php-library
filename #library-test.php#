<html>
  <head>
    <title>PS PHP Library Test</title>
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
      $tmp_api_key = 'd1lg0my9c6y3j5iv5vkc6ayrd';
      $tmp_catalog_id = 'dp4rtmme6tbhugpv6i59yiqmr';
      $api = new PsApiCall($tmp_api_key, $tmp_catalog_id);
      $api->call('products', array('keyword' => 'wallet'));
    ?>
    <hr>
    <h4>
      Demo of library capabilities (look at the PHP source to see what is going on):
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
