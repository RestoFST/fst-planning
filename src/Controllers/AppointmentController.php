<?php

namespace App\Controllers;

use App\Attribute\RenderAttribute;
use App\Attribute\RouteAttribute;
use App\Core\APIRenderer;
use App\Core\TwigRenderer;
use GuzzleHttp\Psr7\Response;

#[RenderAttribute(APIRenderer::class)]
#[RouteAttribute(method: "GET", path: "/api/appointments")]
final class AppointmentController extends BaseController
{
    #[RouteAttribute(method: "GET", path: "/", name: "appointments.list")]
    public function list()
    {
        return new Response(body: $this->render('appointments/index'));
    }

    #[RouteAttribute(method: "GET", path: "/", name: "appointments.set")]
    public function set()
    {
        return new Response(body: $this->render('appointments/set'));
    }

    #[RouteAttribute(method: "GET", path: "/", name: "appointments.delete")]
    public function delete()
    {
        return new Response(body: $this->render('appointments/delete'));
    }

}