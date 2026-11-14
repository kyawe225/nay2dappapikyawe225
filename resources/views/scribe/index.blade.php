<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laravel API Documentation</title>
    <link rel="alternate" type="application/yaml" href="{{ route("scribe.openapi") }}">
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.34.1/swagger-ui.css">
    <style>
        body { margin: 0; }
    </style>
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://unpkg.com/swagger-ui-dist@5.34.1/swagger-ui-bundle.js" crossorigin></script>
    <script>
        window.addEventListener('load', () => {
            SwaggerUIBundle({
                url: document.querySelector('link[rel="alternate"]').href,
                dom_id: '#swagger-ui',
                deepLinking: true,
                validatorUrl: null,
                persistAuthorization: false,
                supportedSubmitMethods: true ? ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'] : [],
            });
        });
    </script>
</body>
</html>
