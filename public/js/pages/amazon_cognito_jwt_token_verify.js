var baseUrl = $('body').attr('data-url');
var jwtToken = localStorage.getItem('jwtToken');
console.log('jwtToken : ' + jwtToken);

if(jwtToken != null)
{
	setInterval(validateCognitoToken, 300000);
}

function validateCognitoToken()
{
	$.post(baseUrl + "/validate-aws-cognito-jwt-token",{'jwtToken' : localStorage.getItem('jwtToken'), '_token' : $('meta[name="csrf-token"]').attr('content')}).then(function(response){
		// Log the response to the console
		if(response.token_status == 'Invalid')
		{
			// Simulate a mouse click:
			window.location.href = baseUrl + "/jwt-token-expired";
			
			// Simulate an HTTP redirect:
			window.location.replace(baseUrl + "/jwt-token-expired");
		}
	});
}