/**
 * @name orchestrate
 * @description Splits tasks into dynamic preparation and execution phases using Opus/Sonnet sub-agents.
 */
workflow("Dynamic Development Pipeline", async (ctx) => {
  // Phase 1: Preparation (Sonnet/Haiku maps the ground)
  await phase("Analyze & Prepare", async () => {
    log("Spawning reconnaissance sub-agent...");
    const repoMap = await agent({
      model: "claude-3-5-sonnet",
      prompt: "Scan the directory context. Identify all files relevant to the user request and return a structured summary map.",
      allowedTools: ["read_file", "glob"] // Strict read-only for safety
    });
    ctx.set("repoMap", repoMap);
  });

  // Phase 2: Design (Fable generates the blueprint)
  let blueprint;
  await phase("Architectural Design", async () => {
    log("Fable is drafting the implementation specs...");
    blueprint = await Fable.designPlan({
      context: ctx.get("repoMap"),
      goal: ctx.userInput
    });
  });

  // Phase 3: Parallel Execution (Opus handles complex engineering logic)
  await phase("Execution", async () => {
    log("Fanning out execution tasks to Claude Opus sub-agents...");
    await parallel(
      blueprint.tasks.map(task => async () => {
        const result = await agent({
          model: "claude-3-5-opus",
          prompt: `Implement the following module strictly matching the architectural blueprint: ${task.description}. Write clean code and comprehensive tests.`,
          allowedTools: ["write_file", "bash"]
        });
        log(`Task completed: ${task.id}`);
      })
    );
  });

  // Phase 4: Review & Verification (Fable acts as Gatekeeper)
  await phase("Review & Adjudication", async () => {
    log("Fable is performing adversarial review and running verification test suites...");
    const reviewStatus = await agent({
      model: "claude-3-5-sonnet",
      prompt: "Run the test runner via bash and scan the git diff for security vulnerabilities or style breaks."
    });
    
    // Final check by the main session orchestrator
    await Fable.adjudicate(reviewStatus);
  });
});
