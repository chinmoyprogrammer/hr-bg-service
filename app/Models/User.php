<?php

namespace App\Models;


use Illuminate\Auth\Authenticatable;
use Laravel\Lumen\Auth\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;

class User extends Model implements AuthenticatableContract, AuthorizableContract
{
    use Authenticatable, Authorizable, HasFactory;


    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $guarded = [];
    public $timestamps = false;
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'username',
        'password',
        'name',
        'email',
        'user_type_id',
        'status',
        'date_of_birth',
        'religion_id',
        'gender_id',
        'blood_group_id',
        'is_draft',
        'force_password_change',
        'login_eligibility',
        'last_login_datetime',
        'last_login_ip',
        'created_user_id',
        'updated_user_id',
        'created_at',
        'updated_at',
        'deleted_by',
        'deleted_at',
    ];

    // Relationships
    public function userType()
    {
        return $this->belongsTo(UserType::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_user_id');
    }

    public function deleter()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeSearch($query, $searchTerm)
    {
        return $query->where('name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('email', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('username', 'LIKE', "%{$searchTerm}%");
    }
    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var string[]
     */
    protected $hidden = [
        'password',
    ];

    public function phoneNumbers()
    {
        return $this->hasMany(PhoneNumber::class);
    }

    // employee_family_nominee.employee_user_id = users.id

    public function familyNominees()
    {
        return $this->hasMany(FamilyNominee::class, 'employee_user_id');
    }

    /**
     * Get the addresses associated with the user.
     */
    public function addresses()
    {
        return $this->hasMany(EmployeeAddress::class, 'employee_user_id');
    }

    /**
     * Get the employee experiences associated with the user.
     */
    public function employeeExperiences()
    {
        return $this->hasMany(EmployeeExperience::class, 'employee_user_id');
    }

    /**
     * Get the employee trainings associated with the user.
     */
    public function employeeTrainings()
    {
        return $this->hasMany(EmployeeTraining::class, 'employee_user_id');
    }

    /**
     * Get the employee skills associated with the user.
     */
    public function employeeSkills()
    {
        return $this->hasMany(EmployeeSkill::class, 'employee_user_id');
    }

    /**
     * Get the employee educations associated with the user.
     */
    public function employeeEducations()
    {
        return $this->hasMany(EmployeeEducation::class, 'employee_user_id');
    }

    /**
     * Get the employee references associated with the user.
     */
    public function employeeReferences()
    {
        return $this->hasMany(EmployeeReference::class, 'employee_user_id');
    }

    /**
     * Get the employee transportations associated with the user.
     */
    public function employeeTransportations()
    {
        return $this->hasMany(EmployeeTransportation::class, 'employee_user_id');
    }

    public function hasOfficialInformation()
    {
        return $this->hasOne(EmployeeOfficialInformation::class, 'employee_user_id', 'id');
    }

    public function hasBasicInformation()
    {
        return $this->hasOne(EmployeeBasicInformation::class, 'employee_user_id', 'id');
    }

    public function hasGender()
    {
        return $this->hasOne(Gender::class, 'id', 'gender_id');
    }

    public function loans()
    {
        return $this->hasMany(PayrollSalaryAdvanceNLoan::class, 'employee_user_id', 'id')->where('type', 'loan');
    }

    public function hasWeekendDays()
    {
        return $this->hasMany(EmployeeWeekendDay::class, 'employee_user_id', 'id');
    }
}
