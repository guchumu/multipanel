<?php

declare(strict_types=1);

namespace Core;

/**
 * Lightweight GraphQL executor for MultiPanel API.
 */
final class GraphQL
{
    /** @var array<string, callable> */
    private array $queries = [];

    /** @var array<string, callable> */
    private array $mutations = [];

    public function query(string $name, callable $resolver): self
    {
        $this->queries[$name] = $resolver;
        return $this;
    }

    public function mutation(string $name, callable $resolver): self
    {
        $this->mutations[$name] = $resolver;
        return $this;
    }

    /** @param array<string, mixed> $request */
    public function execute(array $request, ?int $tenantId = 1): array
    {
        $query = $request['query'] ?? '';
        $variables = $request['variables'] ?? [];

        if (preg_match('/^\s*(query|mutation)\s+(\w+)/', $query, $m)) {
            $operation = $m[1];
            $field = $m[2];
        } elseif (preg_match('/^\s*\{\s*(\w+)/', $query, $m)) {
            $operation = 'query';
            $field = $m[1];
        } else {
            return ['errors' => [['message' => 'Invalid query format']]];
        }

        $resolvers = $operation === 'mutation' ? $this->mutations : $this->queries;
        $resolver = $resolvers[$field] ?? null;

        if (!$resolver) {
            return ['errors' => [['message' => "Unknown field: {$field}"]]];
        }

        try {
            $data = $resolver($variables, $tenantId);
            return ['data' => [$field => $data]];
        } catch (\Throwable $e) {
            return ['errors' => [['message' => $e->getMessage()]]];
        }
    }

    public function getSchema(): string
    {
        return <<<'GQL'
type Query {
  dashboard: DashboardStats
  servers: [Server]
  server(uuid: String!): Server
  mediaUsers(status: String, page: Int): MediaUserList
  mediaUser(uuid: String!): MediaUser
  stats: Stats
  health: Health
}

type Mutation {
  createMediaUser(username: String!, email: String, maxStreams: Int): MediaUser
  suspendMediaUser(uuid: String!): MediaUser
  activateMediaUser(uuid: String!): MediaUser
  syncServer(uuid: String!): ServerSyncResult
}

type DashboardStats {
  usersActive: Int
  usersSuspended: Int
  usersTotal: Int
  serversOnline: Int
  serversTotal: Int
}

type Server {
  uuid: String
  name: String
  type: String
  status: String
  activeSessions: Int
  version: String
}

type MediaUser {
  uuid: String
  username: String
  email: String
  status: String
  maxStreams: Int
  expiresAt: String
}

type MediaUserList {
  data: [MediaUser]
  total: Int
  page: Int
}

type Stats {
  todaySessions: Int
  todayHours: Float
  monthSessions: Int
  mrr: Float
}

type Health {
  status: String
  version: String
}

type ServerSyncResult {
  success: Boolean
  status: String
}
GQL;
    }
}
