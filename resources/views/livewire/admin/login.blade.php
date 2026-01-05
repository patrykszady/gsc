<div>
    <flux:card class="p-8">
        <div class="mb-8 text-center">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Admin Login</h1>
            <p class="mt-2 text-zinc-500 dark:text-zinc-400">Sign in to manage your projects</p>
        </div>

        <form wire:submit="login" class="space-y-6">
            <flux:field>
                <flux:label>Email</flux:label>
                <flux:input 
                    wire:model="email" 
                    type="email" 
                    placeholder="admin@example.com"
                    autofocus
                />
                <flux:error name="email" />
            </flux:field>

            <flux:field>
                <flux:label>Password</flux:label>
                <flux:input 
                    wire:model="password" 
                    type="password" 
                    placeholder="••••••••"
                />
                <flux:error name="password" />
            </flux:field>

            <flux:checkbox wire:model="remember" label="Remember me" />

            <flux:button type="submit" variant="primary" class="w-full">
                Sign in
            </flux:button>
        </form>

        <div class="mt-6 text-center">
            <a href="{{ route('home') }}" class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">
                ← Back to website
            </a>
        </div>
    </flux:card>
</div>
