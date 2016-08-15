<?php
namespace TmlpStats\Exceptions;

use App;
use Auth;
use Carbon\Carbon;
use Exception;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Session\TokenMismatchException;
use Log;
use Mail;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use TmlpStats\Api\Exceptions as ApiExceptions;

class Handler extends ExceptionHandler {

    /**
     * A list of the exception types that should not be reported.
     *
     * @var array
     */
    protected $dontReport = [
        HttpException::class,
        ModelNotFoundException::class,
        TokenMismatchException::class,
    ];

    /**
     * Report or log an exception.
     *
     * This is a great spot to send exceptions to Sentry, Bugsnag, etc.
     *
     * @param  \Exception  $e
     */
    public function report(Exception $e)
    {
        if ($this->shouldReport($e) && !App::runningInConsole() && env('APP_ENV') == 'prod') {

            $user = Auth::user() ? Auth::user()->email : 'unknown';
            $center = Auth::user() && Auth::user()->center
                ? Auth::user()->center->name
                : 'unknown';
            $time = Carbon::now()->format('Y-m-d H:i:s');

            $body = "An exception was caught by '{$user}' from {$center} center at {$time} UTC:\n\n";
            $body .= "Request details:\n";
            $body .= "    Method: '{$_SERVER['REQUEST_METHOD']}'\n";
            $body .= "    Uri: '{$_SERVER['REQUEST_URI']}'\n";
            $body .= "    Query: '{$_SERVER['QUERY_STRING']}'\n\n";
            $body .= "$e";
            try {
                Mail::raw($body, function ($message) use ($center) {
                    $message->to(env('ADMIN_EMAIL'))->subject("Exception processing sheet for {$center} center in " . strtoupper(env('APP_ENV')));
                });
            } catch (Exception $ex) {
                Log::error("Exception caught sending error email: " . $ex->getMessage());
            }
        }
        parent::report($e);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Exception  $e
     * @return \Illuminate\Http\Response
     */
    public function render($request, Exception $e)
    {
        if ($request->ajax() || $request->wantsJson()) {
            $statusCode = 400;
            if ($e instanceof HttpException) {
                $statusCode = $e->getStatusCode();
            }

            if ($e instanceof Arrayable) {
                $error = $e->toArray();
            } else {
                $error = [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage(),
                ];
            }

            $json = [
                'success' => false,
                'error' => $error,
            ];

            return response()->json($json, $statusCode);
        }

        if ($e instanceof ModelNotFoundException) {
            $e = new NotFoundHttpException($e->getMessage(), $e);
        } else if ($e instanceof TokenMismatchException) {
            // Probably a session expiration. Redirect to login
            return redirect('auth/login')->with('message','Your session has expired. Please try logging in again.');
        }

        return parent::render($request, $e);
    }

}
