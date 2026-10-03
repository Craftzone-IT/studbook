<?php

declare(strict_types=1);

namespace Studbook\Controller;

use Studbook\Http\Request;
use Studbook\Http\Response;
use Studbook\View;

final class HomeController
{
    public function __construct(private readonly View $view)
    {
    }

    public function index(Request $request): Response
    {
        return Response::html($this->view->render('home', ['title' => t('home.title')]));
    }
}
