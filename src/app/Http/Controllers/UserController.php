<?php
namespace TmlpStats\Http\Controllers;

use Illuminate\Http\Request;
use Log;
use Mail;
use TmlpStats\User;
use TmlpStats\Role;
use TmlpStats\Center;
use TmlpStats\Person;
use TmlpStats\Region;
use TmlpStats\Http\Requests;
use TmlpStats\Http\Requests\UserRequest;

use Auth;
use DB;

class UserController extends Controller
{
    /**
     * Authenticated admins only
     */
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('role:administrator');
    }

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        $this->authorize('index', User::class);

        $activeUsers = User::active()
            ->where('id', '>', 0) // hide reports user
            ->with('person')
            ->get();

        $inactiveUsers = User::active(false)
            ->with('person')
            ->get();

        return view('users.index', compact('activeUsers', 'inactiveUsers'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create()
    {
        $this->authorize('create', User::class);

        $rolesObjects = Role::all();

        $selectedRole = null;
        $roles = array();
        foreach ($rolesObjects as $role) {
            $roles[$role->id] = $role->display;

            if ($role->name == 'readonly') {
                $selectedRole = $role->id;
            }
        }

        $centerList = DB::table('centers')
            ->select('centers.*', DB::raw('regions.name as regionName'), 'regions.parent_id')
            ->join('regions', 'regions.id', '=', 'centers.region_id')
            ->get();

        $centers = array();
        foreach ($centerList as $center) {
            $parent = ($center->parent_id)
                ? Region::find($center->parent_id)
                : null;

            $regionName = ($parent)
                ? $parent->name
                : $center->regionName;

            $centers[$center->abbreviation] = "{$regionName} - {$center->name}";
        }
        asort($centers);

        return view('users.create', compact('centers', 'roles', 'selectedRole'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(UserRequest $request)
    {
        $this->authorize('create', User::class);

        $input = $request->all();

        $person = Person::create($input);
        $input['person_id'] = $person->id;

        $user = User::create($input);

        if ($request->has('center')) {
            $center = Center::abbreviation($request->get('center'))->first();
            if ($center) {
                $user->setCenter($center);
            }
        }
        if ($request->has('role')) {
            $role = Role::find($request->get('role'));
            if ($role) {
                $user->roleId = $role->id;
            }
        }
        if ($request->has('active')) {
            $user->active = $request->get('active') == true;
        }
        if ($request->has('require_password_reset')) {
            $user->requirePasswordReset = $request->get('require_password_reset') == true;
        }
        $user->save();

        return redirect('admin/users');
    }

    /**
     * Display the specified resource.
     *
     * @param  int $id
     * @return Response
     */
    public function show($id)
    {
        $user = User::findOrFail($id);

        $this->authorize($user);

        return view('users.show', compact('user'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int $id
     * @return Response
     */
    public function edit($id)
    {
        $user = User::findOrFail($id);

        $this->authorize($user);

        $rolesObjects = Role::all();

        $roles = array();
        foreach ($rolesObjects as $role) {
            $roles[$role->id] = $role->display;
        }

        $centerList = DB::table('centers')
            ->select('centers.*', DB::raw('regions.name as regionName'), 'regions.parent_id')
            ->join('regions', 'regions.id', '=', 'centers.region_id')
            ->get();

        $centers = array();
        foreach ($centerList as $center) {
            $parent = ($center->parent_id)
                ? Region::find($center->parent_id)
                : null;

            $regionName = ($parent)
                ? $parent->name
                : $center->regionName;

            $centers[$center->abbreviation] = "{$regionName} - {$center->name}";
        }
        asort($centers);

        return view('users.edit', compact('user', 'roles', 'centers'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int $id
     * @return Response
     */
    public function update(UserRequest $request, $id)
    {
        $user = User::findOrFail($id);
        $this->authorize($user);

        $user->update($request->all());

        if ($request->has('center')) {
            $center = Center::abbreviation($request->get('center'))->first();
            if ($center) {
                $user->setCenter($center);
            }
        }
        if ($request->has('role')) {
            $role = Role::find($request->get('role'));
            if ($role) {
                $user->roleId = $role->id;
            }
        }
        if ($request->has('first_name')) {
            $user->setFirstName($request->get('first_name'));
        }
        if ($request->has('last_name')) {
            $user->setLastName($request->get('last_name'));
        }
        if ($request->has('phone')) {
            $user->setPhone($request->get('phone'));
        }
        if ($request->has('email')) {
            $user->setEmail($request->get('email'));
        }
        if ($request->has('active')) {
            $user->active = $request->get('active') == true;
        }
        if ($request->has('require_password_reset')) {
            $user->requirePasswordReset = $request->get('require_password_reset') == true;
        }
        $user->save();

        $redirect = "admin/users/{$id}";
        if ($request->has('previous_url')) {
            $redirect = $request->get('previous_url');
        }

        return redirect($redirect);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int $id
     * @return Response
     */
    public function destroy($id)
    {
        //
    }

    public function showProfile()
    {
        $user = Auth::user();

        $this->authorize($user);

        $roles = $user->roles;

        return view('users.edit', compact('user', 'roles'));
    }

    public function updateProfile(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $this->authorize($user);

        $user->update($request->all());
        $user->save();

        return redirect('user/profile');
    }

    /**
     * Send activation email
     *
     * @param Request $request
     * @param User    $user
     *
     * @return mixed
     */
    protected function sendActivate(Request $request, User $user)
    {
        $activateUrl = url("/users/activate/{$user->token}");

        try {
            Mail::send('emails.activate', compact('invite', 'activateUrl'),
                function($message) use ($user) {
                    // Only send email to person in production
                    if (env('APP_ENV') === 'prod') {
                        $message->to($user->email);
                    } else {
                        $message->to(env('ADMIN_EMAIL'));
                    }

                    $message->subject("Your TMLP Stats Account Invitation");
                });
            $successMessage = "Success! You are officially registered. We sent an email to {$user->email}. Please follow the instructions in the email to activate your account.";
            if (env('APP_ENV') === 'prod') {
                Log::info("User activation email sent to {$user->email} for invite {$user->id}");
            } else {
                Log::info("User activation email sent to " . env('ADMIN_EMAIL') . " for invite {$user->id}");
                $successMessage .= "<br/><br/><strong>Since this is development, we sent it to " . env('ADMIN_EMAIL') . " instead.</strong>";
            }
            $result['success'][] = $successMessage;

        } catch (\Exception $e) {
            Log::error("Exception caught sending user activation email: " . $e->getMessage());
            $result['error'][] = "Failed to send user activation email to {$user->firstName}. Please try again.";
        }

        return $result;
    }

    // TODO: add feature to activate new accounts
    // TODO: update invite code
    //public function activate(Request $request, $token)
    //{
    //    $user = User::token($token)->first();
    //    if ($user) {
    //        abort(404);
    //    }
    //
    //    $activateUrl = url("/user/activate/{$user->token}");
    //}
}
