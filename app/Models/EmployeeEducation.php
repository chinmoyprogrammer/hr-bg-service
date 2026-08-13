<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeEducation extends Model
{
    //
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'employee_id',
        'education_group_id',
        'subject_id',
        'institution_name',
        'degree',
        'start_date',
        'end_date',
        'gpa',
        'description',
    ];

        protected $table = 'employee_educations';

    /**
     * Get the education group that owns the education record.
     */
    public function educationGroup()
    {
        return $this->belongsTo(EducationGroup::class, 'education_group_id');
    }

    /**
     * Get the subject that owns the education record.
     */
    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    /**
     * Get the employee that owns the education record.
     */
    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
    /**
     * Get the education level that owns the education record.
     */
    public function educationLevel()
    {
        return $this->belongsTo(EducationLevel::class, 'level');
    }

    //exam
    // table : exam_name
    public function exam()
    {
        return $this->belongsTo(ExamDegreeTitle::class, 'exam_name');
    }

    //boardUniversity
    // table : board_university
    public function boardUniversity()
    {
        return $this->belongsTo(EducationBoardUniversity::class, 'board_university');
    }

    //groupSubject
    // table : group_subject
    public function groupSubject()
    {
        return $this->belongsTo(EducationGroupSubject::class, 'group_subject_id');
    }


}
