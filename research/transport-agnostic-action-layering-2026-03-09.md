# Transport-Agnostic Action Layering in Performance-Constrained Web Frameworks
*A structural research analysis of a proposed CitOmni architecture direction.*

---

**Document type:** Research Analysis  
**Version:** 0.1  
**Applies to:** CitOmni ≥ 8.2  
**Audience:** Framework developers, maintainers, and architecture reviewers  
**Status:** Exploratory and non-normative  
**Author:** CitOmni Core Team  
**Copyright:** © 2012-present CitOmni

---

## Abstract

This article examines a proposed layered architecture for the CitOmni PHP framework, in which the classical Model-View-Controller pattern is decomposed into a more granular structure featuring an explicit, transport-agnostic Action layer positioned between delivery adapters and the persistence boundary. The proposal is evaluated against established architectural patterns including MVC, ADR, Clean Architecture, Application Service Layer, and Vertical Slice Architecture. We assess the degree to which the proposal constitutes an original contribution, identify its structural strengths and weaknesses, and discuss the practical and theoretical conditions under which the architecture is likely to succeed or fail. Our principal finding is that the proposal does not introduce a fundamentally novel abstraction, but that its combination of strict performance constraints with an explicit transport-agnostic orchestration layer - within a framework that explicitly rejects dependency injection ceremony - constitutes a meaningful and practically coherent synthesis that is underrepresented in the PHP ecosystem.

---

## 1. Introduction

The question of how to structure server-side application code has occupied software architects for several decades. The dominant answer in the web domain has long been the Model-View-Controller (MVC) pattern, first described by Trygve Reenskaug in the context of Smalltalk-80 [1] and subsequently adapted, reinterpreted, and arguably distorted by the web framework community into a variety of loosely related conventions. The persistence of MVC as a default mental model has not been without critics. Fowler's catalog of enterprise application patterns [2] introduced the concept of the Service Layer as a mediating abstraction between presentation and domain logic, and more recently, the proliferation of microservice architectures, domain-driven design [3], and Clean Architecture [4] has produced a landscape in which the original MVC tripartition is widely acknowledged to be insufficient for non-trivial applications.

Against this backdrop, the CitOmni PHP framework proposes a layered structure that decomposes the classical model layer into three distinct concerns: an Action layer responsible for transport-agnostic orchestration of a single application operation, a Repository layer responsible exclusively for persistence, and a Service layer providing reusable, framework-registered infrastructure tools. The proposal further distinguishes a Util category for pure functions and an Exceptions category for domain-level failure semantics. Delivery adapters - Controllers for HTTP and Commands for CLI - are explicitly positioned as thin translation layers with no domain logic.

The central question addressed by this article is twofold. First, does this structure constitute an original architectural contribution? Second, what are its structural strengths and inherent weaknesses, and under what conditions is it likely to perform well or poorly?

---

## 2. Background and Related Work

### 2.1 MVC and Its Decompositions

The original MVC pattern as applied to web frameworks conflates at least three distinct concerns within the Model component: persistence, domain rules, and application workflow. This conflation has been widely criticized. Fowler [2] distinguishes between Domain Model, Transaction Script, and Service Layer as separate patterns precisely because the undifferentiated "model" concept proves insufficient once application complexity exceeds a certain threshold.

### 2.2 Application Service Layer

Fowler's Application Service Layer pattern [2] describes a layer that defines an application's boundary and its set of available operations. It coordinates the domain object layer in response to requests from client layers, and it is explicitly technology-agnostic. This is the closest established antecedent to the proposed Action layer. The key characteristics are: one service method per application operation, technology-agnostic interfaces, and delegation of domain logic to lower layers. The proposed Action layer reproduces these characteristics almost precisely, with the notable difference that it operates without the interface-driven dependency inversion that characterizes enterprise implementations of the Application Service Layer.

### 2.3 Clean Architecture and Use Cases

Uncle Bob's Clean Architecture [4] places Use Cases at the center of its dependency rule, defining them as application-specific business rules that orchestrate the flow of data to and from entities. The Use Case layer is transport-agnostic by definition and serves as the canonical reuse point across delivery mechanisms. The proposed Action layer is functionally equivalent to this Use Case layer. The primary distinction is philosophical: Clean Architecture insists on dependency inversion and interface abstraction at layer boundaries, whereas the CitOmni proposal explicitly rejects this ceremony in favor of direct instantiation and explicit wiring, accepting a tighter coupling to the framework's App container as an acceptable trade-off for reduced overhead.

### 2.4 ADR (Action-Domain-Responder)

Paul M. Jones' ADR pattern [5] decomposes the HTTP request-response cycle into three components: an Action that handles a single HTTP request, a Domain that contains all business logic, and a Responder that handles output construction. The Action in ADR is transport-specific - it is an HTTP adapter, not a transport-agnostic orchestrator. The naming overlap with the proposed CitOmni Action layer is therefore potentially confusing, but the semantic content is distinct. ADR's Domain is closer to what CitOmni calls Action.

### 2.5 CQRS

Command Query Responsibility Segregation [6] partitions operations into Commands, which mutate state, and Queries, which return data without side effects. This provides an explicit semantic distinction that the proposed Action layer does not enforce. A CitOmni `Action/LoginUser` and `Action/GetUserProfile` are structurally identical despite their fundamentally different nature with respect to side effects. Whether this distinction is architecturally necessary or merely desirable depends on the scale and nature of the application.

### 2.6 Vertical Slice Architecture

Vertical Slice Architecture [7] organizes code around features rather than technical layers. Each feature contains its own controller, business logic, and data access code co-located in a single directory. This approach maximizes cohesion at the feature level at the expense of cross-cutting layer boundaries. It is structurally incompatible with the CitOmni proposal, which maintains horizontal layering as a primary organizing principle.

---

## 3. The Proposed Architecture: A Structural Description

The CitOmni architecture organizes application code into the following layers, in descending order of transport specificity:

**Front Controller** - Transport-specific entry point. Establishes constants, loads the autoloader, and delegates to the transport kernel. Contains no application logic.

**Transport Kernel** - Mode-specific boot sequence. Resolves configuration, routes, and service maps. Installs error handling. Dispatches to the delivery adapter. Exists in two variants: `citomni/http` and `citomni/cli`.

**Controller / Command** - Thin delivery adapters. Responsibilities are explicitly bounded: receive and normalize input, enforce transport-level concerns (CSRF, session, authentication context), optionally delegate to an Action, and translate results to transport output (response, template selection, JSON, exit code). Domain logic is explicitly prohibited.

**Action** - Transport-agnostic application operation. Each class represents exactly one application operation, named with a verb phrase (e.g., `LoginUser`, `ProcessPayment`, `PublishArticle`). Responsibilities: orchestrate transport-agnostic services and repositories, enforce application rules, return a neutral result or throw well-defined exceptions. Instantiated explicitly via `new Action($this->app)` by the calling adapter.

**Service** - Reusable, framework-registered infrastructure tools. Extended from `BaseService`. Registered in the service map. Accessed via `$this->app->{id}`. May perform infrastructure side effects (logging, mailing, caching, formatting).

**Repository** - Persistence boundary. All SQL resides here. Extended from `BaseRepository`. Receives `App` for shared database access. Returns predictable array shapes.

**Util** - Pure functions. No App, no IO, no configuration reads. Input to output only.

**Exceptions** - Transport-agnostic domain and application exceptions. Transport layers translate these into protocol-specific responses.

A critical architectural rule governs the introduction of the Action layer: it is not a default - it is introduced only when at least one of the following conditions holds: the operation must be reusable across HTTP and CLI; the operation coordinates multiple repositories; the operation includes multiple infrastructure side effects; or the operation encodes non-trivial state transition logic. For simple, route-specific operations, the Controller-to-Repository path is preferred and considered sufficient.

---

## 4. Assessment of Originality

The honest assessment is that the proposed architecture does not introduce a fundamentally novel abstraction. The transport-agnostic orchestration layer is well-established under various names: Application Service, Use Case, Domain Service, Interactor. The persistence boundary enforced by the Repository layer is a canonical pattern described by Fowler [2] and Evans [3]. The strict separation of delivery adapters from domain logic is a foundational principle of Clean Architecture, Hexagonal Architecture [8], and ADR.

What the proposal does offer that is less common, and arguably underrepresented in the PHP ecosystem specifically, is the following combination:

1. **Explicit performance constraints as a first-class architectural concern.** Most layered architecture proposals are agnostic with respect to runtime performance. CitOmni's proposal is explicitly performance-driven, and this shapes the architecture in concrete ways: no dependency injection container, no interface-driven inversion, no namespace scanning, direct instantiation with explicit `new`. This is not a theoretical position but an implemented constraint with measurable implications for memory usage and request latency.

2. **Rejection of dependency injection ceremony without abandonment of layer separation.** Clean Architecture and similar proposals typically enforce layer boundaries through interface abstraction and dependency inversion. The CitOmni proposal enforces the same boundaries through documentation, naming convention, and explicit instantiation rules rather than compiler-enforced contracts. This is a pragmatic rather than purist position, but it is coherently argued and internally consistent.

3. **Dual-delivery as a primary architectural motivation.** While Clean Architecture mentions delivery-agnosticism as a desirable property, it is rarely the primary driver of layer introduction in practice. In the CitOmni proposal, transport-agnosticism across HTTP and CLI is explicitly cited as the canonical justification for introducing the Action layer. This is a practical, operator-level concern that is often treated as secondary in academic treatments of layered architecture.

4. **The rule of optional introduction.** Many architectural proposals treat their central abstractions as mandatory. The CitOmni proposal explicitly states that the Action layer should be introduced only when it earns its existence. This is philosophically closer to Extreme Programming's YAGNI principle [9] than to enterprise architecture dogma, and it is a meaningful design choice that prevents the accretion of unnecessary abstraction layers in simple applications.

The combination of these four properties - performance-first constraints, pragmatic boundary enforcement, dual-delivery motivation, and conditional layer introduction - constitutes a synthesis that is not novel in its components but is coherent and distinctly positioned relative to mainstream PHP framework architectures, which tend either toward full-ceremony enterprise patterns (Symfony, Laravel with DDD overlays) or toward thin MVC with no explicit orchestration layer at all.

---

## 5. Structural Strengths

### 5.1 Clear Responsibility Boundaries

The architecture defines unambiguous ownership rules for the primary categories of application logic. SQL belongs in Repository. Transport shaping belongs in adapters. Orchestration belongs in Action. These rules are simple enough to be enforced in code review without specialized tooling and clear enough to guide junior developers without extensive mentorship.

### 5.2 Testability of the Action Layer

By excluding transport objects and SQL from the Action layer, the architecture produces a set of classes that can be unit-tested without HTTP infrastructure, without a database connection, and without a running framework kernel. This is a significant practical advantage. The cost of the test suite is reduced, and the reliability of tests is increased because they are not dependent on infrastructure state.

### 5.3 Mechanical Sympathy with PHP's Request Model

PHP's shared-nothing request model means that every object is instantiated and destroyed within a single request. Architectures that rely on long-lived service graphs or complex object initialization chains pay a disproportionate cost in PHP relative to runtimes that persist across requests. The CitOmni approach of direct instantiation, lazy service resolution, and precompiled configuration and route caches is well-matched to PHP's execution model in a way that heavy dependency injection containers are not.

### 5.4 Dual-Delivery Reuse

The Action layer provides a concrete reuse point for operations that must be available via both HTTP and CLI. This is a common real-world requirement - administrative operations, scheduled jobs, and batch processes frequently need to execute the same application logic that HTTP endpoints expose. The architecture solves this cleanly and explicitly.

### 5.5 Resistance to Model Bloat

By splitting the classical model concern into Action (orchestration), Repository (persistence), Service (reusable infrastructure), and Util (pure computation), the architecture provides four distinct containers for the four genuinely distinct concerns that tend to accumulate in undifferentiated model classes. This structural differentiation reduces the likelihood of the "god class" model that plagues MVC applications of non-trivial size.

---

## 6. Structural Weaknesses and Risks

### 6.1 Authentication Context Propagation

The architecture places authentication and session management in the Controller/Command layer, which is appropriate from a transport-separation standpoint. However, Action classes will frequently require knowledge of the authenticated user context - user ID, role, permissions - in order to enforce application rules. The architecture does not specify an explicit contract for how this context is passed from the adapter layer into the Action layer.

If left unspecified, this gap creates a predictable failure mode: developers will reach for `$this->app->session` inside an Action class, coupling the Action to the HTTP session mechanism and breaking transport-agnosticity. The mitigation is explicit documentation of a convention - the adapter extracts user context and passes it as a parameter to the Action constructor or method - but this convention must be codified, not assumed.

### 6.2 Absence of Mutation/Query Distinction

The architecture does not distinguish between Actions that mutate state and Actions that only read state. `LoginUser` and `GetUserProfile` are structurally identical despite their fundamentally different implications for caching, concurrency, idempotency, and side effect management. This is not a fatal weakness - the distinction can be managed through naming discipline and documentation - but it means the architecture does not enforce a property that CQRS would enforce structurally.

For applications where read/write asymmetry is operationally significant, this absence may become a maintenance liability over time.

### 6.3 Disciplinary Rather Than Structural Enforcement

Several of the architecture's key properties depend on developer discipline rather than structural enforcement. The prohibition on SQL in Action classes, the prohibition on transport objects in Action classes, and the rule that App access in Repository must be limited to persistence-related concerns are all enforced by convention and code review rather than by type system constraints or compiler checks.

This is a deliberate trade-off - the alternative would require interface abstraction and dependency inversion, which contradicts the framework's performance objectives. But it means the architecture degrades gracefully rather than failing loudly when developers violate its contracts. In teams without strong review culture, this is a meaningful risk.

### 6.4 Naming Ambiguity of "Action"

As discussed in the preliminary analysis leading to this article, the term "Action" carries existing connotations in the PHP ecosystem, specifically from Symfony's single-action controller pattern and the ADR pattern, both of which use "Action" to refer to an HTTP-specific adapter rather than a transport-agnostic orchestrator. This semantic collision is not fatal, but it represents an onboarding cost for developers familiar with those conventions, and it may produce subtle misunderstandings about the intended scope of the layer.

### 6.5 Risk of Layer Proliferation

The architecture currently defines six categories (Action, Repository, Service, Util, Exceptions, plus delivery adapters). The boundaries between Action and Service, and between Action and a rich Repository, may become blurred in practice. Specifically: when a Service begins to contain orchestration logic, it starts to look like an Action. When an Action begins to contain infrastructure concerns, it starts to look like a Service. The architecture addresses this with explicit rules, but the rules require ongoing enforcement.

---

## 7. Comparative Performance Assessment

The academic literature on the runtime performance of layered architectures in PHP is sparse, but the general principle is well-established: each additional layer of indirection introduces allocation cost, call stack depth, and - in the case of dependency injection containers - reflection and configuration-parsing overhead at request initialization time. The CitOmni architecture minimizes these costs through precompiled caches, direct instantiation, and lazy service resolution.

Relative to a full Clean Architecture implementation with interface-driven layer boundaries and a dependency injection container, the CitOmni approach would be expected to exhibit lower memory usage at request start, lower time-to-first-byte for simple requests, and a flatter call stack profile. These are measurable advantages in high-throughput scenarios.

Relative to a thin MVC framework with no orchestration layer, the CitOmni architecture introduces one additional instantiation per request for operations that use the Action layer. This cost is negligible in absolute terms and is structurally justified by the benefits described in Section 5.

---

## 8. Conclusion

The proposed CitOmni architecture represents a well-reasoned and practically coherent synthesis of established patterns adapted to the specific constraints of a performance-focused PHP framework that must support both HTTP and CLI delivery without code duplication. Its primary contribution is not the invention of a new abstraction, but the clear articulation of when that abstraction should be introduced, the explicit rejection of dependency injection ceremony as a boundary-enforcement mechanism, and the identification of dual-delivery reuse as the canonical justification for the orchestration layer.

The architecture's principal risks are disciplinary rather than structural: it depends on naming discipline, code review culture, and explicit documentation of contracts that other architectures would enforce through the type system. Whether these risks materialize depends more on the development context than on the architecture itself.

For the PHP ecosystem specifically, where the dominant frameworks tend either toward full-ceremony enterprise patterns or toward thin MVC with no explicit orchestration layer, the CitOmni architecture occupies a coherent and underserved middle position. It is neither academically novel nor practically unimportant. It is, in the most useful sense of the phrase, a well-engineered trade-off.

---

## References

[1] T. Reenskaug, "MVC - XEROX PARC 1978-79," Unpublished notes, 1979.

[2] M. Fowler, *Patterns of Enterprise Application Architecture*. Addison-Wesley, 2002.

[3] E. Evans, *Domain-Driven Design: Tackling Complexity in the Heart of Software*. Addison-Wesley, 2003.

[4] R. C. Martin, *Clean Architecture: A Craftsman's Guide to Software Structure and Design*. Prentice Hall, 2017.

[5] P. M. Jones, "Action-Domain-Responder: A Web-Specific Refinement of Model-View-Controller," 2014. [Online]. Available: https://pmjones.io/adr

[6] G. Young, "CQRS Documents," Unpublished manuscript, 2010.

[7] J. Bogard, "Vertical Slice Architecture," NDC Sydney, 2018.

[8] A. Cockburn, "Hexagonal Architecture," 2005. [Online]. Available: https://alistair.cockburn.us/hexagonal-architecture/

[9] K. Beck, *Extreme Programming Explained: Embrace Change*. Addison-Wesley, 1999.
