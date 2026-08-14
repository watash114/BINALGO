<?php

require_once __DIR__ . '/../../config/database.php';

class User
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function findAll(array $filters = [], int $page = 1, int $per_page = 20): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['role'])) {
            $where[] = "role = :role";
            $params[':role'] = $filters['role'];
        }

        if (!empty($filters['status'])) {
            $where[] = "status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $where[] = "(name LIKE :search OR email LIKE :search2)";
            $params[':search'] = '%' . $filters['search'] . '%';
            $params[':search2'] = '%' . $filters['search'] . '%';
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $count_stmt = $this->db->prepare("SELECT COUNT(*) as total FROM users {$where_clause}");
        $count_stmt->execute($params);
        $total = $count_stmt->fetch()['total'];

        $offset = ($page - 1) * $per_page;

        $stmt = $this->db->prepare(
            "SELECT * FROM users {$where_clause} ORDER BY created_at DESC LIMIT {$per_page} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $users = $stmt->fetchAll();

        return [
            'data'     => $users,
            'total'    => (int) $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => (int) ceil($total / $per_page),
        ];
    }

    public function create(array $data): ?int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO users (username, name, email, password, role, gender, age, phone, status, avatar, created_at)
             VALUES (:username, :name, :email, :password, :role, :gender, :age, :phone, :status, :avatar, :created_at)"
        );

        $stmt->execute([
            ':username'   => $data['username'] ?? '',
            ':name'       => $data['name'] ?? '',
            ':email'      => $data['email'] ?? '',
            ':password'   => $data['password'] ?? '',
            ':role'       => $data['role'] ?? 'tourist',
            ':gender'     => $data['gender'] ?? 'male',
            ':age'        => $data['age'] ?? 18,
            ':phone'      => $data['phone'] ?? '',
            ':status'     => $data['status'] ?? 'pending',
            ':avatar'     => $data['avatar'] ?? null,
            ':created_at' => $data['created_at'] ?? date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [':id' => $id];

        foreach ($data as $key => $value) {
            $fields[] = "{$key} = :{$key}";
            $params[":{$key}"] = $value;
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "updated_at = NOW()";
        $set_clause = implode(', ', $fields);

        $stmt = $this->db->prepare("UPDATE users SET {$set_clause} WHERE id = :id");
        return $stmt->execute($params);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM users WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function countAll(): int
    {
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM users");
        return (int) $stmt->fetch()['count'];
    }

    public function countByRole(string $role): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM users WHERE role = :role");
        $stmt->execute([':role' => $role]);
        return (int) $stmt->fetch()['count'];
    }

    public function getStats(): array
    {
        $total = $this->countAll();
        $guides = $this->countByRole('guide');
        $staff = $this->countByRole('staff');
        $tourists = $this->countByRole('tourist');

        $stmt = $this->db->query("SELECT COUNT(*) as count FROM users WHERE status = 'pending'");
        $pending = (int) $stmt->fetch()['count'];

        $stmt = $this->db->query("SELECT COUNT(*) as count FROM users WHERE status = 'active'");
        $active = (int) $stmt->fetch()['count'];

        return [
            'total_users'          => $total,
            'total_guides'         => $guides,
            'total_staff'          => $staff,
            'total_tourists'       => $tourists,
            'pending_verifications'=> $pending,
            'active_users'         => $active,
        ];
    }

    public function search(string $query): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM users WHERE name LIKE :q1 OR email LIKE :q2 OR phone LIKE :q3
             ORDER BY name ASC LIMIT 20"
        );
        $q = '%' . $query . '%';
        $stmt->execute([':q1' => $q, ':q2' => $q, ':q3' => $q]);
        return $stmt->fetchAll();
    }

    public function updateStatus(int $id, string $status): bool
    {
        $valid = ['active', 'inactive', 'pending', 'suspended'];
        if (!in_array($status, $valid)) {
            return false;
        }

        $stmt = $this->db->prepare("UPDATE users SET status = :status, updated_at = NOW() WHERE id = :id");
        return $stmt->execute([':status' => $status, ':id' => $id]);
    }
}
