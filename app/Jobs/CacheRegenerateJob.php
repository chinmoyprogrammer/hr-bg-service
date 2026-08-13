<?php

namespace App\Jobs;

use App\Models\BankBranch;
use App\Models\BankName;
use App\Models\Branch;
use App\Models\BusinessSetting;
use App\Models\Company;
use App\Models\Department;
use App\Models\District;
use App\Models\Division;
use App\Models\EmployeeBasicInformation;
use App\Models\EmployeeDesignation;
use App\Models\EmployeeDesignationLevel;
use App\Models\EmployeeOfficialInformation;
use App\Models\EmployeeType;
use App\Models\Gender;
use App\Models\LeaveHead;
use App\Models\MediaUploadUsage;
use App\Models\MfsList;
use App\Models\Occupation;
use App\Models\PhoneNumber;
use App\Models\Religion;
use App\Models\Section;
use App\Models\Subsection;
use App\Models\Upazila;
use App\Models\User;
use App\Models\UserStatusNSettings;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;


class CacheRegenerateJob extends Job implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;
    protected array $payload;

    public function __construct(array $payload = [])
    {
        $this->payload = $payload;
        $this->connection = 'rabbitmq';
        $this->queue = 'cacheRegenerate_queue';
    }

    public function handle(): void
    {
        $type = $this->payload['type'] ?? null;
        Log::info('Cache re generate', ['type' => $type]);

        $caches = [
            'employees' => fn() => $this->cacheEmployees(),
            'all_employee_short_desc' => fn() => $this->cacheAllEmployeesShortDesc(),
            'business_settings' => fn() => $this->simpleCache('all_business_settings', BusinessSetting::class),
            'divisions' => fn() => $this->simpleCache('all_divisions', Division::class),
            'districts' => fn() => $this->simpleCache('all_districts', District::class),
            'upazilas' => fn() => $this->simpleCache('all_upazilas', Upazila::class),
            'leave_heads' => fn() => $this->simpleCache('all_leave_heads', LeaveHead::class),
            'religions' => fn() => $this->simpleCache('all_religions', Religion::class),
            'genders' => fn() => $this->simpleCache('all_genders', Gender::class),
            'companies' => fn() => $this->simpleCache('all_companies', Company::class),
            'branches' => fn() => $this->simpleCache('all_branches', Branch::class),
            'departments' => fn() => $this->simpleCache('all_departments', Department::class),
            'sections' => fn() => $this->simpleCache('all_sections', Section::class),
            'subsections' => fn() => $this->simpleCache('all_subsections', Subsection::class),
            'designation_levels' => fn() => $this->simpleCache('all_designation_levels', EmployeeDesignationLevel::class),
            'designations' => fn() => $this->simpleCache('all_designations', EmployeeDesignation::class),
            'employee_types' => fn() => $this->simpleCache('all_employee_types', EmployeeType::class),
            'bank_names' => fn() => $this->simpleCache('all_bank_names', BankName::class),
            'bank_branches' => fn() => $this->simpleCache('all_bank_branches', BankBranch::class),
            'mfs_list' => fn() => $this->simpleCache('all_mfs_list', MfsList::class),
            'pf_policies' => fn() => $this->simpleCacheQuery('all_pf_policies', 'employee_pf_policies'),
            'ot_policies' => fn() => $this->simpleCacheQuery('all_ot_policies', 'employee_ot_policies'),
            'occupations' => fn() => $this->simpleCache('all_occupations', Occupation::class),
        ];

        if(count($type) > 0)
        {
            foreach($type as $item)
            {
                if ($item && isset($caches[$item])) {
                    $caches[$item]();    
                }
            }
        }else
        {
            // If no type provided, run all
            foreach ($caches as $key => $callback) {
                $callback();
            }
        }
    }


    private function simpleCache($key, $modelClass)
    {
        Cache::forget($key);
        return Cache::rememberForever($key, function () use ($modelClass) {
            return $modelClass::all();
        });
    }

    private function simpleCacheQuery($key, $tableName)
    {
        Cache::forget($key);
        return Cache::rememberForever($key, function () use ($tableName) {
            return DB::table($tableName)->get();
        });
    }



    private function cacheEmployees()
    {
        


        $employees = User::select(
                'users.*',
                'blood_groups.name as blood_group_name',
                'blood_groups.name_bn as blood_group_name_bn',
                'employee_basic_information.full_name',
                'employee_basic_information.full_name_bn',
                'employee_basic_information.nickname',
                'employee_basic_information.nickname_bn',
                'employee_basic_information.father_name',
                'employee_basic_information.father_name_bn',
                'employee_basic_information.mother_name',
                'employee_basic_information.mother_name_bn',
                'users.date_of_birth',
                'employee_basic_information.nationality',
                'employee_basic_information.nid_no',
                'employee_basic_information.nid_required',
                'employee_basic_information.birth_certificate_no',
                'employee_basic_information.birth_certificate_required',
                'employee_basic_information.passport_no',
                'employee_basic_information.driving_license',
                'employee_basic_information.tin_no',
                'employee_basic_information.marital_status',
                'employee_basic_information.personal_primary_email',
                'employee_basic_information.personal_other_email',
                'employee_basic_information.official_email',
                //'employee_basic_information.is_draft',
                'employee_official_information.pf_eligibility_status',
                'employee_basic_information.deleted_by',
                'employee_basic_information.child_data_identifier_key_outgoing as basic_child_data_identifier_key_outgoing',

                // 'employee_addresses.address_line',
                // 'employee_addresses.post_code',
                // 'employee_addresses.post_office',
                // 'employee_addresses.ward_no',
                // 'employee_addresses.address',
                // 'employee_addresses.address_bn',

                'religions.id as religion_id',
                'religions.name as religion_name',
                'religions.name_bn as religion_name_bn',

                'genders.id as gender_id',
                'genders.name as gender_name',
                'genders.name_bn as gender_name_bn',

                'employee_official_information.emp_code',
                'employee_official_information.joining_date',
                'employee_official_information.provisioner_days',
                'employee_official_information.confirmation_date',
                'employee_official_information.observation_start_date',
                'employee_official_information.observation_end_date',
                'employee_official_information.observation_days',
                'employee_official_information.gross_salary',
                'employee_official_information.ait_eligible',
                'employee_official_information.salary_payment_mode',
                'employee_official_information.cash_pay_amount',
                'employee_official_information.bank_pay_amount',
                'employee_official_information.mobile_pay_amount',
                'employee_official_information.payment_mobile_number',
                'employee_official_information.bank_account_no',
                'employee_official_information.facilities',
                'employee_official_information.is_mealable',
                'employee_official_information.is_free_meal',
                'employee_official_information.meal_effective_date',
                'employee_official_information.pay_period_basis',
                'employee_official_information.employee_pf_employer_contribution_balance',
                'employee_official_information.employee_pf_balance',
                'employee_official_information.allotted_mobile_balance',
                'employee_official_information.leave_policy_id',
                'leave_policies.policy_title as leave_policy_name',

                'shifts.id as shift_id',
                'shifts.title as shift_name',
                'shifts.clock_in',
                'shifts.clock_out',
                //'shifts.shift_name_bn',

                'companies.id as company_id',
                'companies.company_name',
                'companies.company_name_bn',

                'branches.id as branch_id',
                'branches.branch_name',
                'branches.branch_name_bn',
                'branches.branch_short_name',

                'departments.id as department_id',
                'departments.department_name',
                'departments.department_name_bn',

                'sections.id as section_id',
                'sections.section_name',
                'sections.section_name_bn',

                'subsections.id as subsection_id',
                'subsections.subsection_name',
                'subsections.subsection_name_bn',

                'employee_designation_levels.id as designation_level_id',
                'employee_designation_levels.level_name',
                'employee_designation_levels.level_name_bn',

                'employee_designations.id as designation_id',
                'employee_designations.title as designation_name',
                'employee_designations.title_bn as designation_name_bn',
                'employee_designations.short_name as designation_short_name',
                'employee_designations.short_name_bn as designation_short_name_bn',

                'employee_types.id as employee_type_id',
                'employee_types.employee_type',
                //'employee_types.employee_type_bn',

                'rep_supervisor.full_name as reporting_supervisor_name',
                'rep_supervisor.employee_user_id as reporting_supervisor_user_id',


                'bank_names.id as bank_id',
                'bank_names.name as bank_name',
                //'bank_names.name_bn as bank_name_bn',


                'bank_branches.id as bank_branch_id',
                'bank_branches.branch_name as bank_branch_name',
                //'bank_branches.branch_name_bn as bank_branch_name_bn',

                'mfs_lists.id as mfs_id',
                'mfs_lists.name as mobile_banking_type_name',

                'employee_pf_policies.id as pf_policy_id',
                'employee_pf_policies.policy_name as pf_policy_name',
                'employee_pf_policies.amount_ptc as pf_amount_ptc',
                'employee_pf_policies.max_deduction_amount as pf_max_deduction_amount',


                'employee_ot_policies.id as ot_policy_id',
                'employee_ot_policies.policy_name as ot_policy_name',
                'employee_ot_policies.ot_rate',
                'employee_ot_policies.maximum_ot_hours',
                'employee_ot_policies.multiplier as ot_multiplier',
                'employee_ot_policies.minimum_ot_hours',

                'f_occupation.id as f_occupation_id',
                'f_occupation.name as f_occupation_name',
                'f_occupation.name_bn as f_occupation_name_bn',

                'm_occupation.id as m_occupation_id',
                'm_occupation.name as m_occupation_name',
                'm_occupation.name_bn as m_occupation_name_bn',
            )
                //->with('addresses','employeeReferences','employeeTransportations')
                ->with(
                 [
                'phoneNumbers'=>function($query){
                    $query->whereNull('deleted_by')->whereNull('deleted_at')->whereIn('contact_type',['personal','official']);
                }, 
                'familyNominees'=>function($query){
                    $query->whereNull('deleted_by')->whereNull('deleted_at');
                },
                 'familyNominees.hasPhoneNumbers'=>function($query){
                    $query->whereNull('deleted_by')->whereNull('deleted_at');
                },
                 'employeeExperiences'=>function($query){
                    $query->whereNull('deleted_by')->whereNull('deleted_at');
                },
                 'addresses'=>function($query){
                    $query->whereNull('deleted_by')->whereNull('deleted_at');
                },
                 'employeeTrainings'=>function($query){
                    $query->whereNull('deleted_by')->whereNull('deleted_at');
                },
                 'employeeSkills.skill'=>function($query){
                    $query->whereNull('deleted_by')->whereNull('deleted_at');
                },
                 'employeeEducations.educationLevel'=>function($query){
                    $query->whereNull('deleted_by')->whereNull('deleted_at')->orderBy('edu_order','asc');
                },
                 'employeeEducations.exam'=>function($query){
                    $query->whereNull('deleted_by')->whereNull('deleted_at');
                },
                 'employeeEducations.boardUniversity'=>function($query){
                    $query->whereNull('deleted_by')->whereNull('deleted_at');
                },
                 'employeeEducations.groupSubject'=>function($query){
                    $query->whereNull('deleted_by')->whereNull('deleted_at');
                },
                 'employeeReferences'=>function($query){
                    $query->whereNull('deleted_by')->whereNull('deleted_at');
                },
                 'employeeTransportations'=>function($query){
                    $query->whereNull('deleted_by')->whereNull('deleted_at');
                },
                 'addresses'=>function($query){
                    $query->whereNull('deleted_by')->whereNull('deleted_at');
                },'employeeReferences.hasPhoneNumber'=>function($query){
                    $query->whereNull('deleted_by')->whereNull('deleted_at');
                },'employeeTransportations'=>function($query){
                    $query->whereNull('deleted_by')->whereNull('deleted_at');
                },
                 'hasWeekendDays'=>function($query){
                    $query->select('id','employee_user_id','php_week_day_code','day_name','alternate_starting_date')->whereNull('deleted_by')->whereNull('deleted_at');
                 }
                 ]
                )
                ->leftJoin('blood_groups', 'blood_groups.id', '=', 'users.blood_group_id')
                ->leftJoin('employee_basic_information', 'users.id', '=', 'employee_basic_information.employee_user_id')
                ->leftJoin('religions', 'religions.id', '=', 'users.religion_id')
                ->leftJoin('genders', 'genders.id', '=', 'users.gender_id')
                ->leftJoin('employee_official_information', 'users.id', '=', 'employee_official_information.employee_user_id')
                ->leftJoin('shifts', 'shifts.id', '=', 'employee_official_information.shift_id')
                ->leftJoin('companies', 'companies.id', '=', 'employee_official_information.company_id')
                ->leftJoin('branches', 'branches.id', '=', 'employee_official_information.branch_id')
                ->leftJoin('departments', 'departments.id', '=', 'employee_official_information.department_id')
                ->leftJoin('sections', 'sections.id', '=', 'employee_official_information.section_id')
                ->leftJoin('subsections', 'subsections.id', '=', 'employee_official_information.sub_section_id')
                ->leftJoin('employee_designation_levels', 'employee_designation_levels.id', '=', 'employee_official_information.designation_level_id')
                ->leftJoin('employee_designations', 'employee_designations.id', '=', 'employee_official_information.designation_id')
                ->leftJoin('employee_types', 'employee_types.id', '=', 'employee_official_information.employee_type_id')
                ->leftJoin('employee_basic_information  as rep_supervisor', 'employee_official_information.reporting_supervisor_user_id', '=', 'rep_supervisor.employee_user_id')
                ->leftJoin('bank_names', 'bank_names.id', '=', 'employee_official_information.bank_name_id')
                ->leftJoin('bank_branches', 'bank_branches.id', '=', 'employee_official_information.branch_name_id')
                ->leftJoin('mfs_lists', 'mfs_lists.id', '=', 'employee_official_information.mobile_banking_type')
                ->leftJoin('employee_pf_policies', 'employee_pf_policies.id', '=', 'employee_official_information.employee_pf_policy_id')
                ->leftJoin('employee_ot_policies', 'employee_ot_policies.id', '=', 'employee_official_information.employee_ot_policy_id')
                ->leftJoin('leave_policies', 'leave_policies.id', '=', 'employee_official_information.leave_policy_id')
                //->leftJoin('phone_numbers', 'users.id', '=', 'phone_numbers.user_id')
                //->leftJoin('employee_family_nominee as f_nominee', 'phone_numbers.family_nominee_id', '=', 'f_nominee.id')
                ->leftJoin('occupations as f_occupation', 'employee_basic_information.father_occupation_id', '=', 'f_occupation.id')
                ->leftJoin('occupations as m_occupation', 'employee_basic_information.mother_occupation_id', '=', 'm_occupation.id')
                //->leftJoin('employee_addresses', 'users.id', '=', 'employee_addresses.employee_user_id')
                // ->leftJoin('divisions', 'employee_addresses.division_id', '=', 'divisions.id')
                // ->leftJoin('districts', 'employee_addresses.district_id', '=', 'districts.id')
                // ->leftJoin('upazilas', 'employee_addresses.upazila_id', '=', 'upazilas.id')
                //->leftJoin('employee_family_nominee','employee_family_nominee.employee_user_id','=','users.id')
                //->leftJoin('employee_experiences','employee_experiences.employee_user_id','=','users.id')
                //->leftJoin('employee_trainings','employee_trainings.employee_user_id','=','users.id')
                //->leftJoin('employee_skills','employee_skills.employee_user_id','=','users.id')
                //->leftJoin('employee_educations','employee_educations.employee_user_id','=','users.id')
                //->leftJoin('education_groups_subjects','education_groups_subjects.id','=','employee_educations.groups_subject_id')
                //->leftJoin('employee_references', 'employee_references.employee_user_id', '=', 'users.id')
                //->leftJoin('employee_transportations', 'employee_transportations.employee_user_id', '=', 'users.id')
                //->where('users.status', 1)
                // ->where('users.is_draft', 0)
                //->where('users.user_type_id', 1)
                ->orderBy('users.id', 'desc')
                ->get();
            // Batch-load every media path in a few grouped queries instead of one
            // fetch_media_path() DB hit per record/relation (the previous N+1).
            $mediaKeys = [];
            foreach ($employees as $data) {
                $basic = $data->basic_child_data_identifier_key_outgoing;
                if ($basic) {
                    $mediaKeys[] = $basic;
                    $mediaKeys[] = $basic . "_nid_no_image";
                    $mediaKeys[] = $basic . "_birth_certificate_no_image";
                    $mediaKeys[] = $basic . "_passport_no_image";
                    $mediaKeys[] = $basic . "_driving_license_image";
                    $mediaKeys[] = $basic . "_tin_no_image";
                    $mediaKeys[] = $basic . "_cv_file";
                }
                foreach (($data->employeeEducations ?? []) as $education) {
                    if ($education->child_data_identifier_key_outgoing) {
                        $mediaKeys[] = $education->child_data_identifier_key_outgoing;
                    }
                }
                foreach (($data->employeeExperiences ?? []) as $experience) {
                    if ($experience->child_data_identifier_key_outgoing) {
                        $mediaKeys[] = $experience->child_data_identifier_key_outgoing;
                    }
                }
                foreach (($data->employeeTrainings ?? []) as $training) {
                    if ($training->child_data_identifier_key_outgoing) {
                        $mediaKeys[] = $training->child_data_identifier_key_outgoing;
                    }
                }
                foreach (($data->familyNominees ?? []) as $nominee) {
                    if ($nominee->child_data_identifier_key_outgoing) {
                        $nk = $nominee->child_data_identifier_key_outgoing;
                        $mediaKeys[] = $nk . "_nominee_picture_file";
                        $mediaKeys[] = $nk . "_nominee_identification_file";
                        $mediaKeys[] = $nk . "_nominee_acknowledgement_file";
                    }
                }
            }
            $mediaKeys = array_values(array_unique(array_filter($mediaKeys)));

            $mediaByKey = collect();
            foreach (array_chunk($mediaKeys, 2000) as $chunk) {
                $rows = MediaUploadUsage::query()
                    ->selectRaw("CONCAT(media_servers.base_url, '~/', media_uploads.file_name) as file_path, media_uploads.title, media_upload_usage.id, media_upload_usage.child_data_identifier_key_incoming as media_key")
                    ->whereIn('media_upload_usage.child_data_identifier_key_incoming', $chunk)
                    ->whereNull('media_upload_usage.deleted_at')
                    ->whereNull('media_upload_usage.deleted_by')
                    ->join('media_uploads', 'media_upload_usage.media_upload_id', '=', 'media_uploads.id')
                    ->join('media_servers', 'media_uploads.media_server_id', '=', 'media_servers.id')
                    ->orderBy('media_uploads.id', 'desc')
                    ->get();
                // Keys are unique per chunk, so no group spans chunks.
                foreach ($rows->groupBy('media_key') as $k => $group) {
                    $mediaByKey[$k] = $group;
                }
            }

            // Mirrors fetch_media_path()'s return shape, reading from the pre-loaded map.
            $mediaLookup = function ($key) use ($mediaByKey) {
                if ($key && $mediaByKey->has($key)) {
                    return $mediaByKey->get($key)->map(function ($row) {
                        return (object) [
                            'file_path' => $row->file_path,
                            'title' => $row->title,
                            'id' => $row->id,
                        ];
                    })->values();
                }
                return collect(['file_path' => null, 'title' => null]);
            };

            // Add emp_photo field to each employee record
            $employees = $employees->map(function ($data) use ($mediaLookup) {
                //education academic_certification_file
                $data->nid_no_image = $mediaLookup($data->basic_child_data_identifier_key_outgoing."_nid_no_image");
                $data->birth_certificate_no_image = $mediaLookup($data->basic_child_data_identifier_key_outgoing."_birth_certificate_no_image");
                $data->passport_no_image = $mediaLookup($data->basic_child_data_identifier_key_outgoing."_passport_no_image");
                $data->driving_license_image = $mediaLookup($data->basic_child_data_identifier_key_outgoing."_driving_license_image");
                $data->cv_file = $mediaLookup($data->basic_child_data_identifier_key_outgoing."_cv_file");
                $data->tin_no_image = $mediaLookup($data->basic_child_data_identifier_key_outgoing."_tin_no_image");

                // Append academic_certificate_file to each education row
                if ($data->employeeEducations) {
                    $data->employeeEducations->each(function ($education) use ($mediaLookup) {
                        $education->academic_certificate_file = $mediaLookup($education->child_data_identifier_key_outgoing);
                    });
                }

                if ($data->employeeExperiences) {
                    $data->employeeExperiences->each(function ($experience) use ($mediaLookup) {
                        $experience->experience_certification_file = $mediaLookup($experience->child_data_identifier_key_outgoing);
                    });
                }

                if ($data->employeeTrainings) {
                    $data->employeeTrainings->each(function ($training) use ($mediaLookup) {
                        $training->training_certification_file = $mediaLookup($training->child_data_identifier_key_outgoing);
                    });
                }

                $data->nominee_picture_file = optional($data->familyNominees)->map(function ($nominee) use ($mediaLookup) {
                    return $mediaLookup($nominee->child_data_identifier_key_outgoing."_nominee_picture_file");
                })->filter()->values()->toArray();

                if ($data->familyNominees) {
                    $data->familyNominees->each(function ($nominee) use ($mediaLookup) {
                        $nominee->nominee_picture_file = $mediaLookup($nominee->child_data_identifier_key_outgoing."_nominee_picture_file");
                        $nominee->nominee_identification_file = $mediaLookup($nominee->child_data_identifier_key_outgoing."_nominee_identification_file");
                        $nominee->nominee_acknowledgement_file = $mediaLookup($nominee->child_data_identifier_key_outgoing."_nominee_acknowledgement_file");
                    });
                }

                $data->emp_photo = $mediaLookup($data->basic_child_data_identifier_key_outgoing);
                //Log::info('child_data_identifier_key_outgoing is null for user '.$data->id,['value'=>$data->child_data_identifier_key_outgoing,'employee_user_id'=>$data->employee_user_id]);
                
                // $phones = PhoneNumber::where('user_id', $data->employee_user_id)->where('contact_type', 'reference')->notDeleted()->active()->get()->map(function ($p) {
                //     return $p->country_code.$p->phone_number;
                // });

                if ($data->employeeReferences) {
                    $data->employeeReferences->each(function ($reference) {
                        $reference->country_code = $reference->hasPhoneNumber->country_code ?? '';
                        $reference->phone_number = $reference->hasPhoneNumber->phone_number ?? '';
                        $reference->hasPhoneNumber = null;
                    });
                }

                if ($data->employeeEducations) {
                    $sortedEducations = $data->employeeEducations
                        ->sortByDesc(function ($education) {
                            return (int) (optional($education->educationLevel)->edu_order ?? 0);
                        }, SORT_NUMERIC)
                        ->values();

                    $data->setRelation('employeeEducations', $sortedEducations);
                }


                
                return $data;
            });
            Cache::forget('all_employees');
            return Cache::rememberForever('all_employees', function () use ($employees) 
        {
            return $employees;   
        });
    }
    private function cacheAllEmployeesShortDesc()
    {
        $employees = EmployeeBasicInformation::selectRaw('
        users.id as user_id,
        employee_basic_information.employee_user_id,
        users.name as full_name,
        employee_official_information.emp_code,
        employee_basic_information.personal_primary_email,
        employee_basic_information.personal_other_email,
        employee_basic_information.official_email,
        employee_basic_information.nickname,
        users.status,
        employee_basic_information.date_of_birth,
        employee_official_information.joining_date,
        employee_basic_information.religion_id,
        employee_basic_information.child_data_identifier_key_outgoing,
        religions.name as religion_name,
        users.gender_id,
        genders.name as gender_name,
        users.blood_group_id,
        blood_groups.name as blood_group_name,
        employee_official_information.branch_id,
        branches.branch_name,
        branches.branch_short_name,
        employee_official_information.department_id,
        departments.department_name,
        departments.department_short_name,
        employee_official_information.section_id,
        sections.section_name,
        sections.section_short_name,
        employee_official_information.sub_section_id,
        subsections.subsection_name,
        employee_official_information.designation_id,
        employee_designations.title as designation_name,
        employee_official_information.designation_level_id,
        employee_designation_levels.level_name,
        shifts.id as shift_id,
        shifts.title as shift_name,
        shifts.clock_in,
        shifts.clock_out
        ')
        ->with(['phoneNumbers',
        'employeePhoto'=>function($query){
            $query->whereNull('media_uploads.deleted_at');
        }
        ])
        ->leftJoin('users', function ($join) {
            $join->on('employee_basic_information.employee_user_id', '=', 'users.id')
                 ->whereNull('users.deleted_at')
                 ->where('users.status',1)
                 ;
        })
        ->whereNull('users.deleted_at')
        ->leftJoin('employee_official_information','employee_official_information.employee_user_id','users.id')
        //->leftJoin('employee_basic_information','employee_basic_information.employee_user_id','users.id')
        ->leftJoin('religions', function ($join) {
            $join->on('religions.id', '=', 'users.religion_id')
                 ->whereNull('religions.deleted_at')
                 ;
        })
        ->leftJoin('genders', function ($join) {
            $join->on('genders.id', '=', 'users.gender_id')
                 ->whereNull('genders.deleted_at')
                 ;
        })
        ->leftJoin('blood_groups', function ($join) {
            $join->on('blood_groups.id', '=', 'users.blood_group_id')
                 ->whereNull('blood_groups.deleted_at')
                 ;
        })
        ->leftJoin('branches', function ($join) {
            $join->on('branches.id', '=', 'employee_official_information.branch_id')
                 ->whereNull('branches.deleted_at')
                 ;
        })
        ->leftJoin('departments', function ($join) {
            $join->on('departments.id', '=', 'employee_official_information.department_id')
                  ->whereNull('departments.deleted_at')
                 ;
        })
        ->leftJoin('sections', function ($join) {
            $join->on('sections.id', '=', 'employee_official_information.section_id')
                 //  ->whereNull('sections.deleted_at')
                 ;
        })
        ->leftJoin('subsections', function ($join) {
            $join->on('subsections.id', '=', 'employee_official_information.sub_section_id')
                  ->whereNull('subsections.deleted_at')
                 ;
        })
        ->leftJoin('employee_designations', function ($join) {
            $join->on('employee_designations.id', '=', 'employee_official_information.designation_id')
                  ->whereNull('employee_designations.deleted_at')
                 ;
        })
        ->leftJoin('employee_designation_levels','employee_designation_levels.id','employee_designations.designation_level_id')
        ->leftJoin('shifts','shifts.id','employee_official_information.shift_id')
        ->get();

        $employees->each(function ($employee) {
            $photo = $employee->employeePhoto;
            $employee->setRelation('employeePhotos', $photo ? collect([$photo]) : collect());
            $employee->unsetRelation('employeePhoto');
        });
        Cache::forget('all_employee_short_desc');
        return Cache::rememberForever('all_employee_short_desc', function() use ($employees) {
            return $employees;
        });
        
    }


}