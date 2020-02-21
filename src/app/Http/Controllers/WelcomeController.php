<?php
namespace TmlpStats\Http\Controllers;

use TmlpStats\Http\Requests\Request;

class WelcomeController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('guest');
    }

    /**
     * Show the application welcome screen to the user.
     *
     * @return Response
     */
    public function index()
    {
        return view('welcome');
    }

    /**
     * Show the application welcome screen to the user.
     *
     * @param Request $request
     * @return Response
     */
    public function apply(Request $request)
    {
        $source = $request->input('source');
        return view('apply', ['source' => $source]);
    }

}
