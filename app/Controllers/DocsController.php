<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\Controller;
use Core\Request;
use Core\Response;

/**
 * OpenAPI documentation controller.
 */
class DocsController extends Controller
{
    public function openapi(Request $request): Response
    {
        $spec = json_decode(file_get_contents(base_path('docs/openapi.json')) ?: '{}', true);
        return $this->json($spec);
    }

    public function swagger(Request $request): Response
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <title>MultiPanel API Docs</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
</head>
<body>
<div id="swagger-ui"></div>
<script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
<script>
SwaggerUIBundle({ url: '/api/docs/openapi.json', dom_id: '#swagger-ui' });
</script>
</body>
</html>
HTML;
        return Response::html($html);
    }
}
