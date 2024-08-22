<?php
$swagger_docs = 'https://' . $_SERVER['SERVER_NAME'] . $_SERVER['PHP_SELF'];
$swagger_docs = str_replace('api-docs.php','US3LIMS-DBinst-API.yaml',$swagger_docs);
echo <<<HTML
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="description" content="SwaggerUI" />
    <title>US3 LIMS Database Instance API</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.11.0/swagger-ui.css" />
  </head>
  <body>
  <div id="swagger-ui"></div>
  <script src="https://unpkg.com/swagger-ui-dist@5.11.0/swagger-ui-bundle.js" crossorigin></script>
  <script src="https://unpkg.com/swagger-ui-dist@5.11.0/swagger-ui-standalone-preset.js" crossorigin></script>
  <script>
    window.onload = () => {
      window.ui = SwaggerUIBundle({
        url: '$swagger_docs',
        dom_id: '#swagger-ui',
        presets: [
          SwaggerUIBundle.presets.apis
        ],
      });
    };
  </script>
  </body>
</html>

HTML;

?>
