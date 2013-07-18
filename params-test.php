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
      $api = new PsApiCall(array('account' => 'd1lg0my9c6y3j5iv5vkc6ayrd', 'catalog' => 'dp4rtmme6tbhugpv6i59yiqmr', 'logging' => false, 
				 'url-mode-prefix' => 'psapi-', 'url-mode' => true));
      $api->get('products');
    ?>
    <hr>
    <h4>
      Demo of library deal capabilities (look at the PHP source to see what is going on):
    </h4>
    <?php
    foreach ($api->resource('products') as $product) {
      print('<br>');
      //print('<img src=' . $product->smallestImageUrl() . '>');
      print($product->attr('name'));
    }
    ?>
  </body>
</html>
