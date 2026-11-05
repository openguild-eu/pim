<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name') }} - Access Your Workspace</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 flex items-center justify-center min-h-screen">
    <div class="w-full max-w-md px-6">
        <div class="bg-white shadow-lg rounded-lg p-8">
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-gray-900 mb-2">{{ config('app.name') }}</h1>
                <p class="text-gray-600">Enter your workspace name to continue</p>
            </div>

            <form method="POST" action="{{ route('subdomain.lookup') }}">
                @csrf

                <div class="mb-6">
                    <label for="subdomain" class="block text-sm font-medium text-gray-700 mb-2">
                        Subdomain
                    </label>
                    <div class="relative">
                        <input
                            type="text"
                            id="subdomain"
                            name="subdomain"
                            value="{{ old('subdomain') }}"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-transparent @error('subdomain') border-red-500 @enderror"
                            placeholder="your subdomain"
                            required
                            autofocus
                        >
                        <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-400">
                            .{{ config('app.central_domain', 'pim.test') }}
                        </div>
                    </div>
                    @error('subdomain')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <button
                    type="submit"
                    class="w-full bg-amber-500 hover:bg-amber-600 text-white font-semibold py-3 px-4 rounded-lg transition duration-200 ease-in-out transform hover:scale-[1.02]"
                >
                    Continue to Login
                </button>
            </form>


        </div>
    </div>
</body>
</html>
