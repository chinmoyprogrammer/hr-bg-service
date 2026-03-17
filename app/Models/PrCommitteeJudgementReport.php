<?php
// app/Models/PrCommitteeJudgementReport.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrCommitteeJudgementReport extends Model
{

    protected $table = 'pr_committee_judgement_report';
    protected $guarded = [];

    protected $casts = [
        'verdict' => 'integer',
        'penalty_amount' => 'decimal:2',
        'installment_amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'is_fully_paid' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    // Constants for verdict field
    const VERDICT_GUILTY = 1;
    const VERDICT_NOT_GUILTY = 0;

    // Relationships
    public function committee()
    {
        return $this->belongsTo(PrCommittee::class, 'pr_committee_id');
    }

    public function problemRegister()
    {
        return $this->belongsTo(PrProblemRegister::class, 'pr_id');
    }

    public function judge()
    {
        return $this->belongsTo(User::class, 'judge_user_id');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_user_id');
    }

    // Scopes
    public function scopeByCommittee($query, $committeeId)
    {
        return $query->where('pr_committee_id', $committeeId);
    }

    public function scopeByProblemRegister($query, $prId)
    {
        return $query->where('pr_id', $prId);
    }

    public function scopeByJudge($query, $userId)
    {
        return $query->where('judge_user_id', $userId);
    }

    public function scopeGuilty($query)
    {
        return $query->where('verdict', self::VERDICT_GUILTY);
    }

    public function scopeNotGuilty($query)
    {
        return $query->where('verdict', self::VERDICT_NOT_GUILTY);
    }

    public function scopeWithPenalty($query)
    {
        return $query->whereNotNull('penalty_amount')->where('penalty_amount', '>', 0);
    }

    public function scopePaid($query)
    {
        return $query->where('is_fully_paid', true);
    }

    public function scopeUnpaid($query)
    {
        return $query->where('is_fully_paid', false);
    }

    public function scopeWithBalance($query)
    {
        return $query->whereNotNull('balance')->where('balance', '>', 0);
    }

    public function scopeActiveCommittees($query)
    {
        return $query->whereHas('committee', function($q) {
            $q->notDeleted();
        });
    }

    // Helper methods
    public function getVerdictTextAttribute()
    {
        $verdictLabels = [
            self::VERDICT_GUILTY => 'Guilty',
            self::VERDICT_NOT_GUILTY => 'Not Guilty'
        ];

        return $verdictLabels[$this->verdict] ?? 'Pending';
    }

    public function getVerdictColorAttribute()
    {
        $verdictColors = [
            self::VERDICT_GUILTY => 'danger',
            self::VERDICT_NOT_GUILTY => 'success'
        ];

        return $verdictColors[$this->verdict] ?? 'secondary';
    }

    public function isGuilty()
    {
        return $this->verdict === self::VERDICT_GUILTY;
    }

    public function isNotGuilty()
    {
        return $this->verdict === self::VERDICT_NOT_GUILTY;
    }

    public function hasPenalty()
    {
        return !empty($this->penalty_amount) && $this->penalty_amount > 0;
    }

    public function hasInstallmentPlan()
    {
        return !empty($this->installment_count) && $this->installment_count > 0;
    }

    public function getJudgementDetailsExcerpt($length = 100)
    {
        if (!$this->judgement_details) {
            return null;
        }

        $excerpt = strip_tags($this->judgement_details);
        if (strlen($excerpt) > $length) {
            $excerpt = substr($excerpt, 0, $length) . '...';
        }

        return $excerpt;
    }

    public function calculateInstallmentAmount()
    {
        if ($this->installment_count && $this->installment_count > 0 && $this->penalty_amount) {
            return $this->penalty_amount / $this->installment_count;
        }
        return null;
    }

    public function getRemainingInstallments()
    {
        if (!$this->hasInstallmentPlan() || !$this->balance) {
            return 0;
        }

        if ($this->installment_amount && $this->installment_amount > 0) {
            return ceil($this->balance / $this->installment_amount);
        }

        return 0;
    }

    public function getPaidAmount()
    {
        if ($this->penalty_amount && $this->balance) {
            return $this->penalty_amount - $this->balance;
        }
        return $this->penalty_amount;
    }

    public function getPaymentPercentage()
    {
        if ($this->penalty_amount && $this->penalty_amount > 0) {
            $paid = $this->getPaidAmount();
            return ($paid / $this->penalty_amount) * 100;
        }
        return 0;
    }

    public function isInActiveCommittee()
    {
        return $this->committee && !$this->committee->deleted_at;
    }

    // Static helper methods
    public static function getVerdictOptions()
    {
        return [
            self::VERDICT_GUILTY => 'Guilty',
            self::VERDICT_NOT_GUILTY => 'Not Guilty'
        ];
    }

    public static function validateVerdict($verdict)
    {
        return in_array($verdict, [
            self::VERDICT_GUILTY,
            self::VERDICT_NOT_GUILTY
        ]);
    }
}
