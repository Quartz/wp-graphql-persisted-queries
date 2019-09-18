# WPGraphQL Persisted Queries

Persisted GraphQL queries allow a GraphQL client to optimistically send a hash
of the query instead of the full query; if the server has seen the query
before, it can satisfy the request. This saves network overhead and makes it
possible to move to `GET` requests instead of `POST`. The primary benefit of
`GET` requests is that they can be cached at the edge (e.g., with Varnish).

This plugin requires [WPGraphQL][wp-graphql] 0.2.0 or newer.

## Compatibility

Apollo Client provides an easy implementation of persisted queries:

https://github.com/apollographql/apollo-link-persisted-queries#automatic-persisted-queries

This plugin aims to be compatible with that implementation, but will work with
any client that sends a `queryId` alongside the `query`. Make sure your client
also sends `operationName` or `operation_name` with the optimistic request.

## Implementation

When the client provides a query hash or ID, that query will be persisted in a
custom post type. By default, this post type will not be visible in the
dashboard.

Query IDs are case-insensitive (i.e., `MyQuery` and `myquery` are equivalent).

## Caching

This plugin does nothing to implement, amend, or purge page (edge) caching. It
is up to you to make page caching work for you. Remember that WPGraphQL has a
single monolithic endpoint at `/graphql` and persisted query requests will
arrive as `GET` requests to that endpoint with unique query strings. Cache
wisely!

## Filters

### `graphql_persisted_queries_post_type`

- Default: `'graphql_query'`
- The custom post type used to persist queries. If empty, queries will not be
  persisted.

### `graphql_persisted_queries_post_type_args`

- Args passed to register_post_type for custom post type. Filter to expose the
  custom post type in the admin UI or in GraphQL:

  ```
  query PersistedQueryQuery {
    persistedQueries {
      nodes {
        id
        title
        content(format: RAW)
      }
    }
  }
  ```

### `graphql_persisted_queries_load_query`

- Default: `null`
- Override the default query loading implementation.

### `graphql_persisted_queries_save_query`

- Override the query (post data) that will be persisted.

[wp-graphql]: https://github.com/wp-graphql/wp-graphql
