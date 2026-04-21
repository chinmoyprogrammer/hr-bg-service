<?php
 
namespace App\DTOs;
 
class AttendanceDTO
{
    public function __construct(
        public readonly int     $employeeId,
        public readonly string  $date,       // Y-m-d
        public readonly ?string $inTime,     // H:i:s or null
        public readonly ?string $outTime,    // H:i:s or null
        public readonly string  $source,     // 'manual' | 'device' | 'mobile' ...
        public readonly ?string $deviceId   = null,
        public readonly ?int    $approvedBy = null,
    ) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date)) {
            throw new \InvalidArgumentException("Invalid date format: {$this->date}. Expected Y-m-d.");
        }
 
        if (!in_array($this->source, ['manual', 'device', 'mobile'])) {
            throw new \InvalidArgumentException("Invalid source: {$this->source}");
        }
    }
}
 