using Microsoft.AspNetCore.Http;
using Microsoft.Extensions.DependencyInjection;

namespace Astrana.TrustedAttestation.Server.Tests.Support;

/// <summary>
/// Runs a handler's result against a bare response, so a test can see what actually goes on the wire:
/// the status, whether there is a body, and whether there is a Content-Type.
/// </summary>
internal static class ExecutedResult
{
    public static async Task<HttpResponse> Of(IResult result)
    {
        var context = new DefaultHttpContext
        {
            RequestServices = new ServiceCollection().AddLogging().BuildServiceProvider(),
        };
        context.Response.Body = new MemoryStream();

        await result.ExecuteAsync(context);

        return context.Response;
    }
}
