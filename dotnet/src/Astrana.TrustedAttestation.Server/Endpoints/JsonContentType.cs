namespace Astrana.TrustedAttestation.Server.Endpoints;

/// <summary>
/// JSON responses go out as a bare <c>application/json</c>. ASP.NET Core appends <c>; charset=utf-8</c>, the
/// other two implementations do not, and since JSON is UTF-8 by definition the parameter carries no
/// information. Dropping it makes the manifest and every API response byte-identical across all three.
/// </summary>
public static class JsonContentType
{
    /// <summary>
    /// Placed outermost in the pipeline, so its callback sees the content type each endpoint set, just before
    /// the headers are written.
    /// </summary>
    public static IApplicationBuilder UseBareJsonContentType(this IApplicationBuilder app) =>
        app.Use(async (context, next) =>
        {
            context.Response.OnStarting(() =>
            {
                DropCharset(context.Response);
                return Task.CompletedTask;
            });

            await next();
        });

    internal static void DropCharset(HttpResponse response)
    {
        var contentType = response.ContentType;
        if (contentType is not null
            && contentType.StartsWith("application/json", StringComparison.OrdinalIgnoreCase)
            && contentType.Contains("charset", StringComparison.OrdinalIgnoreCase))
        {
            response.ContentType = "application/json";
        }
    }
}
