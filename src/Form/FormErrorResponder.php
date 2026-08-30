<?php

namespace App\Form;

use App\Content\SiteCopy;
use App\Controller\PageController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * Re-renders a form page with the submitted values and error markers.
 */
class FormErrorResponder
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function respond(Request $request, string $template, string $errorKey, int $status, array $errors = [], array $extra = []): Response
    {
        $lang = PageController::localeOf($request);

        return new Response($this->twig->render($template, $extra + [
            't' => SiteCopy::for($lang),
            'lang' => $lang,
            'old' => $request->request->all(),
            'errors' => $errors ?: ['form' => $errorKey],
            'error_key' => $errorKey,
            'section' => null,
        ]), $status);
    }
}
