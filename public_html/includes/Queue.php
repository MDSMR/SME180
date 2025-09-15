<?php
namespace App\Queue;

class Queue {
    private $db;
    private $tenantId;
    
    public function __construct($db, $tenantId = null) {
        $this->db = $db;
        $this->tenantId = $tenantId;
    }
    
    /**
     * Push a job to the queue
     */
    public function push($queue, $job, $data = [], $delay = 0) {
        $payload = json_encode([
            'job' => $job,
            'data' => $data,
            'attempts' => 0,
            'created_at' => time()
        ]);
        
        $availableAt = time() + $delay;
        
        $stmt = $this->db->prepare("
            INSERT INTO queue_jobs (tenant_id, queue, payload, available_at, created_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        return $stmt->execute([
            $this->tenantId,
            $queue,
            $payload,
            $availableAt,
            time()
        ]);
    }
    
    /**
     * Get the next available job from the queue
     */
    public function pop($queue = 'default', $timeout = 60) {
        $this->db->beginTransaction();
        
        try {
            // Find next available job
            $stmt = $this->db->prepare("
                SELECT * FROM queue_jobs
                WHERE queue = ?
                AND reserved_at IS NULL
                AND available_at <= ?
                ORDER BY id ASC
                LIMIT 1
                FOR UPDATE
            ");
            
            $stmt->execute([$queue, time()]);
            $job = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$job) {
                $this->db->rollBack();
                return null;
            }
            
            // Reserve the job
            $stmt = $this->db->prepare("
                UPDATE queue_jobs
                SET reserved_at = ?,
                    attempts = attempts + 1
                WHERE id = ?
            ");
            
            $stmt->execute([time() + $timeout, $job['id']]);
            $this->db->commit();
            
            return new QueueJob($this, $job);
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Delete a completed job
     */
    public function delete($jobId) {
        $stmt = $this->db->prepare("DELETE FROM queue_jobs WHERE id = ?");
        return $stmt->execute([$jobId]);
    }
    
    /**
     * Release a job back to the queue
     */
    public function release($jobId, $delay = 0) {
        $stmt = $this->db->prepare("
            UPDATE queue_jobs
            SET reserved_at = NULL,
                available_at = ?
            WHERE id = ?
        ");
        
        return $stmt->execute([time() + $delay, $jobId]);
    }
    
    /**
     * Get queue statistics
     */
    public function stats($queue = null) {
        $conditions = [];
        $params = [];
        
        if ($this->tenantId) {
            $conditions[] = "tenant_id = ?";
            $params[] = $this->tenantId;
        }
        
        if ($queue) {
            $conditions[] = "queue = ?";
            $params[] = $queue;
        }
        
        $where = $conditions ? "WHERE " . implode(" AND ", $conditions) : "";
        
        $stmt = $this->db->prepare("
            SELECT 
                queue,
                COUNT(*) as total,
                SUM(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN 1 ELSE 0 END) as available,
                SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) as reserved,
                SUM(CASE WHEN available_at > ? THEN 1 ELSE 0 END) as delayed
            FROM queue_jobs
            $where
            GROUP BY queue
        ");
        
        $stmt->execute(array_merge([time(), time()], $params));
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}

class QueueJob {
    private $queue;
    private $data;
    
    public function __construct(Queue $queue, array $data) {
        $this->queue = $queue;
        $this->data = $data;
    }
    
    public function getId() {
        return $this->data['id'];
    }
    
    public function getPayload() {
        return json_decode($this->data['payload'], true);
    }
    
    public function getAttempts() {
        return $this->data['attempts'];
    }
    
    public function delete() {
        return $this->queue->delete($this->getId());
    }
    
    public function release($delay = 0) {
        return $this->queue->release($this->getId(), $delay);
    }
    
    public function fail($exception = null) {
        // Log failure
        error_log("Job {$this->getId()} failed: " . ($exception ? $exception->getMessage() : 'Unknown error'));
        
        // Delete or move to failed jobs table
        if ($this->getAttempts() >= 3) {
            $this->delete();
        } else {
            $this->release(60); // Retry after 1 minute
        }
    }
}