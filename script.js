const landingView = document.getElementById("landingView");
const loginView = document.getElementById("loginView");
const openLogin = document.getElementById("openLogin");
const backHome = document.getElementById("backHome");
const segments = document.querySelectorAll(".segment");
const loginForm = document.getElementById("loginForm");
const emailInput = document.getElementById("email");
const passwordInput = document.getElementById("password");
const patientLogin = document.getElementById("patientLogin");
const staffLogin = document.getElementById("staffLogin");
const loginMessage = document.getElementById("loginMessage");

function showView(view) {
  const showLogin = view === "login";

  landingView.classList.toggle("active", !showLogin);
  loginView.classList.toggle("active", showLogin);

  window.scrollTo({
    top: 0,
    behavior: "smooth"
  });
}

openLogin.addEventListener("click", () => {
  showView("login");
});

backHome.addEventListener("click", () => {
  showView("landing");
});

segments.forEach((segment) => {
  segment.addEventListener("click", () => {
    segments.forEach((item) => {
      item.classList.remove("active");
      item.setAttribute("aria-selected", "false");
    });

    segment.classList.add("active");
    segment.setAttribute("aria-selected", "true");
  });
});

function showLoginMessage(message, type) {
  loginMessage.textContent = message;
  loginMessage.className = `login-message ${type}`;
}

function handleLogin(accountType) {
  if (!loginForm.checkValidity()) {
    loginForm.reportValidity();
    showLoginMessage("Please enter a valid email address and password.", "error");
    return;
  }

  const email = emailInput.value.trim();
  const password = passwordInput.value.trim();

  if (!email || !password) {
    showLoginMessage("Please fill in your email and password.", "error");
    return;
  }

  showLoginMessage(`Login successful. Welcome ${accountType}, ${email}.`, "success");
}

patientLogin.addEventListener("click", () => {
  handleLogin("Patient");
});

staffLogin.addEventListener("click", () => {
  handleLogin("Staff");
});

loginForm.addEventListener("submit", (event) => {
  event.preventDefault();
  handleLogin("Patient");
});

