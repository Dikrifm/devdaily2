<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class TestController extends Controller
{
    public function index()
    {
        return '<h1>Halo! Controller berhasil dipanggil.</h1>';
    }
}
