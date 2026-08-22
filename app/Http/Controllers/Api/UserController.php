<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Employee;
use App\Models\UserApplicationAccess;
use App\Models\UserEnterpriseAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * `User::enterprises()`/`applications()` apuntan a las tablas legadas
     * `user_enterprises`/`user_applications`, que están vacías — el sistema
     * de permisos real usado por el login (AuthController::getUserPermissions)
     * y por "Gestionar Permisos" (UserHierarchicalPermissionsModal) vive en
     * las tablas jerárquicas `user_enterprise_access`/`user_application_access`.
     * Leer de las tablas legadas hacía que el panel admin mostrara "Sin
     * asignar" para usuarios que sí tienen empresas/aplicaciones asignadas.
     */
    public function index()
    {
        $enterpriseAccessByUser = UserEnterpriseAccess::where('is_active', true)
            ->get()
            ->groupBy('user_id');

        $applicationAccessByUser = UserApplicationAccess::where('is_active', true)
            ->with('application:id,enterprise_id')
            ->get()
            ->groupBy('user_id');

        $users = User::all()->map(function ($user) use ($enterpriseAccessByUser, $applicationAccessByUser) {
            $enterpriseIds = ($enterpriseAccessByUser->get($user->id) ?? collect())
                ->pluck('enterprise_id')
                ->toArray();

            $applicationsByEnterprise = ($applicationAccessByUser->get($user->id) ?? collect())
                ->filter(fn ($access) => $access->application)
                ->groupBy(fn ($access) => $access->application->enterprise_id)
                ->map(fn ($group) => $group->pluck('application_id')->toArray())
                ->toArray();

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone ?? null,
                'role' => $user->role ?? 'user',
                'created_at' => $user->created_at,
                'permissions' => [
                    'enterprises' => $enterpriseIds,
                    'applications' => $applicationsByEnterprise,
                ]
            ];
        });

        return response()->json($users);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'phone' => 'nullable|string|max:20',
            'role' => 'nullable|in:user,admin',
            'employee_id' => 'nullable|exists:employees,id',
        ]);

        $employeeId = $validated['employee_id'] ?? null;
        unset($validated['employee_id']);

        $validated['password'] = Hash::make($validated['password']);
        $validated['role'] = $validated['role'] ?? 'user';

        $user = User::create($validated);

        // Si se especificó un empleado, vincularlo al usuario
        if ($employeeId) {
            Employee::where('id', $employeeId)->update(['user_id' => $user->id]);
        }

        return response()->json([
            'message' => 'Usuario creado exitosamente',
            'user' => $user->load('employee')
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $user = User::findOrFail($id);

        $enterpriseIds = UserEnterpriseAccess::where('user_id', $user->id)
            ->where('is_active', true)
            ->pluck('enterprise_id');

        $applicationsByEnterprise = UserApplicationAccess::where('user_id', $user->id)
            ->where('is_active', true)
            ->with('application:id,enterprise_id')
            ->get()
            ->filter(fn ($access) => $access->application)
            ->groupBy(fn ($access) => $access->application->enterprise_id)
            ->map(fn ($group) => $group->pluck('application_id')->toArray());

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone ?? null,
            'role' => $user->role ?? 'user',
            'created_at' => $user->created_at,
            'permissions' => [
                'enterprises' => $enterpriseIds,
                'applications' => $applicationsByEnterprise,
            ],
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => 'sometimes|string|min:8',
            'phone' => 'nullable|string|max:20',
            'role' => 'sometimes|in:user,admin',
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);

        return response()->json([
            'message' => 'Usuario actualizado exitosamente',
            'user' => $user
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = User::findOrFail($id);

        \App\Models\ActivityLog::log(
            action: 'delete',
            model: 'User',
            modelId: $user->id,
            // Excluye password/remember_token — nunca deben quedar en el log.
            oldValues: collect($user->getAttributes())->except($user->getHidden())->all(),
        );

        $user->delete();

        return response()->json([
            'message' => 'Usuario eliminado exitosamente'
        ]);
    }

    /**
     * Get employees without linked user account
     */
    public function employeesWithoutUser(Request $request)
    {
        $query = Employee::whereNull('user_id')
            ->where('status', 'active')
            ->with(['department', 'position', 'enterprise']);

        // Filtrar por empresa si se especifica
        if ($request->has('enterprise_id')) {
            $query->where('enterprise_id', $request->enterprise_id);
        }

        $employees = $query->get()->map(function ($employee) {
            return [
                'id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'full_name' => $employee->full_name,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'email' => $employee->email,
                'phone' => $employee->phone ?? $employee->mobile,
                'department' => $employee->department?->name,
                'position' => $employee->position?->name,
                'enterprise_id' => $employee->enterprise_id,
                'enterprise_name' => $employee->enterprise?->name,
            ];
        });

        return response()->json($employees);
    }
}
