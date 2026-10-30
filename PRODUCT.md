# Product

## Register

This document defines the product direction, user needs, and design principles for the MyAPES Account interface.

## Product vocabulary

| Public name | Meaning |
|-------------|---------|
| **MyAPES Account** | The product: signed-in chrome, Admin, dashboard, changelog |
| **Modules** | Organisation areas: APES CIC, APES Shelter and Rescue, APES Pet Care Clinic. Each has its own navigation and chooses which plugins it uses. |
| **Plugins** | Reusable features a module enables: Tickets, Cases, Pet Profiles, Consultations, Recruitment |

The layer names are Core, Module, and Plugin ([docs/architecture.md](docs/architecture.md)). Code still says sub-core for a module and module for a plugin until the structure epic moves it. On-screen labels follow the glossary ([docs/glossary.md](docs/glossary.md)) and `lang/en_GB/terms.php`; do not invent a second UI word in product copy. Workflow statuses, priorities and similar stored values are always shown as Title Case labels (In Progress, Waiting on User), never as raw snake_case ([docs/localisation.md](docs/localisation.md#workflow-labels)). "Service" as a name for an organisation area is a deprecated synonym of Module.

## Users
MyAPES Account serves two core user groups: public service users and APES staff/administrators. Public users manage service requests, personal profile details, and pet records across APES CIC, APES Shelter and Rescue, and APES Pet Care Clinic. Staff and admin users operate in a task-heavy workflow that requires fast navigation, case/ticket visibility, and reliable operational controls.

## Product Purpose
MyAPES Account centralizes support, shelter, and APES Pet Care Clinic workflows into one secure portal with role-appropriate access. It exists to reduce friction for service users while giving staff a dependable operational interface for tickets, pet profiles, rescue/shelter cases, and consultations. Success means users can quickly complete their next action with confidence and clear context about which module they are in.

## Brand Personality
Friendly, reassuring, practical. The product voice should feel welcoming and compassionate (aligned with animal welfare and community support), but remain clear and dependable for administrative work. Emotional goals are trust, warmth, and momentum.

## Anti-references
- Overly corporate dashboards that feel sterile and intimidating.
- Gimmicky novelty UIs that sacrifice readability, clarity, or task speed.
- Visual clutter patterns (excessive decorative gradients, noisy card stacks, or low-contrast text on tinted backgrounds).

## Design Principles
1. Keep tasks obvious: every screen should make the next action clear in one glance.
2. Keep the module visible: users should always know whether they are in APES CIC, APES Shelter and Rescue, or APES Pet Care Clinic.
3. Be welcoming without being distracting: personality should support trust and focus, not compete with content.
4. Design for mixed audiences: public users need guidance; staff users need speed and consistency.
5. Make quality repeatable: shared styles and components should keep behavior and visual language consistent across Plugins.

## Accessibility & Inclusion
Target WCAG 2.2 AA contrast and interaction standards across all core flows. Preserve keyboard accessibility for forms, navigation, and actions. Respect reduced-motion preferences, avoid color-only status communication, and keep copy plain enough for broad community audiences with varied digital confidence.
